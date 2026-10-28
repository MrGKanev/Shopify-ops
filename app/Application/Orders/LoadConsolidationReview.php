<?php

namespace App\Application\Orders;

use App\Domain\Orders\OrderConsolidationPlanner;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use Carbon\CarbonImmutable;
use LogicException;
use Throwable;

class LoadConsolidationReview
{
    public const MAX_GROUPS = 50;

    public function __construct(private readonly ShipStationClientFactory $clients, private readonly OrderConsolidationPlanner $planner) {}

    /**
     * Read-only. Reads the store's open ShipStation orders (awaiting shipment or on hold) and lists the groups that could ship together.
     *
     * @return array{groups: list<array<string, mixed>>, total_groups: int, considered: int, read_at: string}
     */
    public function handle(Store $store, int $windowDays): array
    {
        $client = $this->clients->forStore($store);
        $storeNumber = ShipStationIdentifier::positiveInt((string) $store->store_number);
        if ($client === null || $storeNumber === null) {
            throw new LogicException('ShipStation is not configured for this store.');
        }
        $orders = array_map(fn (array $order): array => $this->normalize($order), $client->fetchBacklogOrders($storeNumber));
        $orders = array_values(array_filter($orders, fn (array $order): bool => $order['number'] !== ''));
        $decisions = $store->consolidationDecisions()->pluck('decision', 'order_number')->all();
        $plan = $this->planner->plan($orders, $windowDays, $decisions);

        return ['groups' => array_slice($plan['groups'], 0, self::MAX_GROUPS), 'total_groups' => count($plan['groups']), 'considered' => $plan['considered'], 'read_at' => CarbonImmutable::now()->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{number: string, status: string, created_at: CarbonImmutable|null, email: string, recipient: string, destination: string|null, warehouse: string, service: string, confirmation: string, paid_shipping: bool, international: bool, insured: bool, gift: bool, special: bool, weight: array{value: float, units: string}|null, lines: list<array{sku: string, name: string, quantity: int}>}
     */
    private function normalize(array $order): array
    {
        $shipTo = is_array($order['shipTo'] ?? null) ? $order['shipTo'] : [];
        $options = is_array($order['advancedOptions'] ?? null) ? $order['advancedOptions'] : [];
        $text = fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
        $key = fn (mixed $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace(['.', ','], '', $text($value))) ?? ''));
        $parts = array_map(fn (string $field): string => $key($shipTo[$field] ?? ''), ['street1', 'street2', 'city', 'state', 'postalCode', 'country']);
        $destination = $parts[0] === '' || $parts[2] === '' || $parts[4] === '' || $parts[5] === '' ? null : implode('|', $parts);
        $created = null;
        try {
            $created = is_string($order['orderDate'] ?? null) ? CarbonImmutable::parse($order['orderDate']) : null;
        } catch (Throwable) {
            $created = null;
        }
        $weight = is_array($order['weight'] ?? null) && is_numeric($order['weight']['value'] ?? null) && (float) $order['weight']['value'] > 0 && $text($order['weight']['units'] ?? '') !== '' ? ['value' => (float) $order['weight']['value'], 'units' => $text($order['weight']['units'])] : null;
        $lines = [];
        foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $item) {
            if (! is_array($item) || ($item['adjustment'] ?? false) === true) {
                continue;
            }
            $lines[] = ['sku' => $text($item['sku'] ?? ''), 'name' => $text($item['name'] ?? ''), 'quantity' => max(1, (int) ($item['quantity'] ?? 1))];
        }
        $international = (is_array($order['internationalOptions']['customsItems'] ?? null) && $order['internationalOptions']['customsItems'] !== []) || $text($order['internationalOptions']['contents'] ?? '') !== '';
        $service = mb_strtolower(implode('|', [$text($order['requestedShippingService'] ?? ''), $text($order['carrierCode'] ?? ''), $text($order['serviceCode'] ?? '')]));
        $special = false;
        foreach (['containsAlcohol', 'dryIce', 'nonMachinable', 'saturdayDelivery', 'shipperRelease', 'deliveredDutyPaid'] as $flag) {
            $special = $special || ! empty($options[$flag]);
        }

        return [
            'number' => ltrim($text($order['orderNumber'] ?? ''), '#'),
            'status' => $text($order['orderStatus'] ?? ''),
            'created_at' => $created,
            'email' => mb_strtolower($text($order['customerEmail'] ?? '')),
            'recipient' => $text($shipTo['name'] ?? '').' '.$text($shipTo['company'] ?? ''),
            'destination' => $destination,
            'warehouse' => $text($options['warehouseId'] ?? ''),
            'service' => $service === '||' ? '' : $service,
            'confirmation' => $text($order['confirmation'] ?? ''),
            'paid_shipping' => is_numeric($order['shippingAmount'] ?? null) && (float) $order['shippingAmount'] > 0,
            'international' => $international,
            'insured' => ! empty($order['insuranceOptions']['insureShipment']),
            'gift' => ($order['gift'] ?? false) === true,
            'special' => $special,
            'weight' => $weight,
            'lines' => $lines,
        ];
    }
}
