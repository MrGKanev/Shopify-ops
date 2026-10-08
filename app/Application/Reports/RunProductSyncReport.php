<?php

namespace App\Application\Reports;

use App\Application\Orders\LoadOrderForRemediation;
use App\Domain\Reports\PackageWeightAnalyzer;
use App\Domain\Reports\ProductSyncAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\ShopifyCustoms;
use App\Integrations\Shopify\ShopifyProductSync;
use App\Models\Store;
use UnexpectedValueException;

class RunProductSyncReport
{
    public function __construct(private readonly ShopifyProductSync $shopify, private readonly ShopifyCustoms $customs, private readonly ShipStationClientFactory $clients, private readonly LoadOrderForRemediation $orders, private readonly ProductSyncAnalyzer $catalog, private readonly PackageWeightAnalyzer $weights) {}

    /** @param array<string, mixed> $input
     * @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, array $input): ReportResult
    {
        $client = $this->clients->forStore($store) ?? throw new UnexpectedValueException('ShipStation credentials are required.');
        $defaults = $client->customsProducts();
        if ($input['mode'] === 'catalog') {
            $data = $this->shopify->catalog($store);
            $rows = $this->catalog->catalog($data['variants'], $defaults['products'], ! $data['truncated'], ! $defaults['truncated']);

            return new ReportResult(rows: $rows, scanned: count($data['variants']), pages: $data['pages'] + $defaults['pages'], truncated: $data['truncated'] || $defaults['truncated'], params: $input, meta: ['mode' => 'catalog', 'checked_at' => now()->toIso8601String()]);
        }
        $base = $this->orders->shopify($store, $input['order_number']);
        $order = $this->orders->shipStation($store, $base);
        if ($order === null || (int) data_get($order, 'advancedOptions.storeId') !== (int) $store->store_number || (int) $store->store_number < 1) {
            throw new UnexpectedValueException('A ShipStation order in the configured store is required.');
        }
        $shipments = array_values(array_filter($client->getOrderShipments((string) $order['orderNumber'], true), fn (array $shipment): bool => (int) ($shipment['orderId'] ?? 0) === (int) $order['orderId'] && ($shipment['voided'] ?? null) === false && ($shipment['isReturnLabel'] ?? null) === false));
        foreach ($shipments as $shipment) {
            if (! is_int($shipment['shipmentId'] ?? null) || $shipment['shipmentId'] < 1) {
                throw new UnexpectedValueException('ShipStation returned an invalid shipment identity.');
            }
        }
        $choices = array_map(fn (array $shipment): array => ['id' => $shipment['shipmentId'], 'tracking' => $shipment['trackingNumber'] ?? '', 'ship_date' => $shipment['shipDate'] ?? ''], $shipments);
        if (empty($input['shipment_id'])) {
            return new ReportResult(rows: [], scanned: count($shipments), params: $input, meta: ['mode' => 'select_shipment', 'shipments' => $choices]);
        }
        $selected = array_values(array_filter($shipments, fn (array $shipment): bool => (string) $shipment['shipmentId'] === (string) $input['shipment_id']));
        if (count($selected) !== 1) {
            throw new UnexpectedValueException('Select one non-voided shipment from this order.');
        }
        $data = $this->customs->productSyncOrder($store, 'gid://shopify/Order/'.$base['id']);
        $lines = $data['lines'];
        $bundles = [];
        foreach ($lines as &$line) {
            if (is_array($line['variant'] ?? null) && ($line['variant']['requiresComponents'] ?? null) === true) {
                $id = $line['variant']['id'];
                $bundles[$id] ??= $this->shopify->withBundle($store, $line['variant']);
                $line['variant'] = $bundles[$id];
            }
        }
        unset($line);
        $analysis = $this->weights->analyze($lines, $order, $selected[0], $defaults['products'], $input, ! $data['truncated']);

        return new ReportResult(rows: $analysis['rows'], scanned: count($analysis['rows']), truncated: $data['truncated'] || $defaults['truncated'], params: $input, meta: ['mode' => 'shipment', 'shipment_id' => (int) $input['shipment_id'], 'summary' => $analysis['summary'], 'customs_declarations' => is_array(data_get($order, 'internationalOptions.customsItems')) ? array_values(array_filter(data_get($order, 'internationalOptions.customsItems'), is_array(...))) : [], 'checked_at' => now()->toIso8601String()]);
    }
}
