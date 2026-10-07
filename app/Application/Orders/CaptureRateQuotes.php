<?php

namespace App\Application\Orders;

use App\Domain\Orders\RateComparisonUnavailable;
use App\Domain\Orders\RemediationUnavailable;
use App\Domain\Reports\AddressCheckAnalyzer;
use App\Domain\Reports\RateShoppingAnalyzer;
use App\Models\RateQuoteSnapshot;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class CaptureRateQuotes
{
    public function __construct(private readonly LoadOrderForRemediation $orders, private readonly AddressCheckAnalyzer $addresses, private readonly RateShoppingAnalyzer $analyzer) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function context(Store $store, array $input): array
    {
        try {
            $order = $this->orders->shipStationByNumber($store, $input['order_number']) ?? throw new RateComparisonUnavailable('No matching ShipStation order was found.');
        } catch (RemediationUnavailable $exception) {
            throw new RateComparisonUnavailable($exception->getMessage());
        }
        $warehouse = $this->orders->client($store)->getWarehouse((int) $input['warehouse_id']);
        if ((string) ($warehouse['warehouseId'] ?? '') !== (string) $input['warehouse_id'] || ! is_array($warehouse['originAddress'] ?? null) || ! is_array($order['shipTo'] ?? null)) {
            throw new RateComparisonUnavailable('Complete origin and destination addresses are required.');
        }
        $from = $warehouse['originAddress'];
        $to = $order['shipTo'];
        $to['residential'] = (bool) $input['residential'];
        foreach ([$from, $to] as $address) {
            $normalized = ['first_name' => $address['name'] ?? 'Recipient', 'address1' => $address['street1'] ?? '', 'address2' => $address['street2'] ?? '', 'city' => $address['city'] ?? '', 'province_code' => $address['state'] ?? '', 'zip' => $address['postalCode'] ?? '', 'country_code' => $address['country'] ?? '', 'phone' => $address['phone'] ?? ''];
            foreach ($this->addresses->check($normalized, []) as $issue) {
                if ($issue['level'] === 'critical') {
                    throw new RateComparisonUnavailable('Fix incomplete route addresses before requesting rates.');
                }
            }
        }
        $this->requireComparable($order, $from, $to);
        $weight = ['value' => (float) $input['weight_value'], 'units' => $input['weight_unit']];
        $dimensions = ['length' => (float) $input['length'], 'width' => (float) $input['width'], 'height' => (float) $input['height'], 'units' => $input['dimension_unit']];
        $services = $input['allowed_services'];
        sort($services);

        return [
            'account' => hash('sha256', $store->shipstation_api_key.'|'.$store->shipstation_api_secret),
            'carrier' => $input['carrier_code'], 'carrier_account' => 'default', 'currency' => $input['currency'], 'currency_source' => 'operator_confirmed',
            'from' => $from, 'to' => $to, 'warehouse_id' => (int) $input['warehouse_id'],
            'weight' => $weight, 'dimensions' => $dimensions, 'package_code' => $input['package_code'],
            'confirmation' => $input['confirmation'], 'timezone' => $store->shopTimezone(), 'pricing_date' => CarbonImmutable::now($store->shopTimezone())->toDateString(),
            'allowed_services' => $services, 'max_transit_days' => (int) $input['max_transit_days'],
            'minimum_coverage' => (float) $input['minimum_coverage'], 'tracking_required' => (bool) $input['tracking_required'],
            'eligibility_source' => 'operator_approved', 'insurance' => 'no_additional_insurance', 'duties' => 'domestic_no_ddp',
            'source_order' => $order,
            'request' => ['carrierCode' => $input['carrier_code'], 'packageCode' => $input['package_code'], 'fromWarehouseId' => (string) $input['warehouse_id'], 'fromPostalCode' => $from['postalCode'], 'fromCity' => $from['city'], 'fromState' => $from['state'] ?? '', 'toPostalCode' => $to['postalCode'], 'toCity' => $to['city'], 'toState' => $to['state'] ?? '', 'toCountry' => $to['country'], 'residential' => $to['residential'], 'weight' => $weight, 'dimensions' => $dimensions, 'confirmation' => $input['confirmation']],
        ];
    }

    /** @param array<string, mixed> $order
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to */
    public function requireComparable(array $order, array $from, array $to): void
    {
        if (($from['country'] ?? '') !== ($to['country'] ?? '')) {
            throw new RateComparisonUnavailable('International and DDP comparisons require a pricing API that includes duties and service conditions. ShipStation V1 comparisons are limited to domestic routes.');
        }
        if (($order['insuranceOptions']['insureShipment'] ?? false) || ($order['advancedOptions']['deliveredDutyPaid'] ?? false)) {
            throw new RateComparisonUnavailable('Additional insurance and DDP costs are not represented by the V1 rate response. This shipment is not comparable.');
        }
        $options = $order['advancedOptions'] ?? [];
        foreach (['billToAccount', 'billToCountryCode', 'billToPostalCode', 'containsAlcohol', 'dryIce', 'nonMachinable', 'saturdayDelivery', 'shipperRelease'] as $field) {
            if (! empty($options[$field])) {
                throw new RateComparisonUnavailable('Third-party billing or special shipment conditions are not represented by the V1 rate request.');
            }
        }
        $metadataFields = ['warehouseId', 'storeId', 'customField1', 'customField2', 'customField3', 'source', 'mergedOrSplit', 'mergedIds', 'parentId', 'billToParty', 'notifyCustomer', 'notifySalesChannel'];
        foreach ($options as $field => $value) {
            if (! empty($value) && ! in_array($field, $metadataFields, true)) {
                throw new RateComparisonUnavailable('A shipping option is not represented by the V1 rate request. No comparable price can be asserted.');
            }
        }
        if (! empty($options['billToParty']) && $options['billToParty'] !== 'my_account') {
            throw new RateComparisonUnavailable('Rate comparison requires the default carrier billing account.');
        }
    }

    public function handle(RateQuoteSnapshot $snapshot): void
    {
        $context = $this->context($snapshot->store, $snapshot->input);
        $signature = self::fingerprint($context);
        $cached = Cache::lock('rate-quotes-lock:'.$signature, 240)->block(5, function () use ($context, $snapshot, $signature): array {
            $key = 'rate-quotes:'.$signature;
            $cached = Cache::get($key);
            if (is_array($cached) && isset($cached['quotes'], $cached['quoted_at'])) {
                return $cached;
            }
            $quotes = $this->orders->client($snapshot->store)->getRates($context['request']);
            $cached = ['quotes' => $quotes, 'quoted_at' => CarbonImmutable::now()->toIso8601String()];
            Cache::put($key, $cached, 120);

            return $cached;
        });
        $snapshot->update(['context' => $context, 'signature' => $signature, 'shipstation_order_id' => $context['source_order']['orderId'], 'quotes' => $this->analyzer->normalize($cached['quotes'], $context['allowed_services']), 'quoted_at' => $cached['quoted_at'], 'status' => 'ready', 'message' => null]);
    }

    /** @param array<string, mixed> $context */
    public static function fingerprint(array $context): string
    {
        return hash('sha256', json_encode(self::canonical($context), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
