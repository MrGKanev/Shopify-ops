<?php

namespace App\Application\Orders;

use App\Domain\Orders\PreShipmentDriftAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\ShipStation\ShipStationOrderNormalizer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use Carbon\CarbonImmutable;
use LogicException;

class AuditPreShipmentDrift
{
    public const MAX_ORDERS = 50;

    public function __construct(
        private readonly ShipStationClientFactory $clients,
        private readonly ShopifyOrders $shopify,
        private readonly ShipStationOrderNormalizer $normalizer,
        private readonly PreShipmentDriftAnalyzer $analyzer,
    ) {}

    /**
     * Read-only. Compares the oldest open (awaiting shipment or on hold) ShipStation orders of the store with the quantities Shopify still needs shipped.
     * Both sources are read fresh on every call; nothing is written or reviewed-and-remembered.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, checked: int, shipstation_read_at: string, shopify_read_at: string}
     */
    public function handle(Store $store, int $limit = self::MAX_ORDERS): array
    {
        $client = $this->clients->forStore($store);
        $storeNumber = ShipStationIdentifier::positiveInt((string) $store->store_number);
        if ($client === null || $storeNumber === null) {
            throw new LogicException('ShipStation is not configured for this store.');
        }
        $grouped = [];
        foreach ($client->fetchBacklogOrders($storeNumber) as $order) {
            $number = ltrim(trim((string) ($order['orderNumber'] ?? '')), '#');
            if ($number === '') {
                continue;
            }
            $grouped[$number][] = $order;
        }
        $shipStationReadAt = CarbonImmutable::now()->toIso8601String();
        $total = count($grouped);
        $grouped = array_slice($grouped, 0, $limit, true);
        $shipStation = [];
        foreach ($grouped as $number => $orders) {
            $number = (string) $number;
            foreach ($orders as $order) {
                $items = array_values(array_filter(is_array($order['items'] ?? null) ? $order['items'] : [], fn (mixed $item): bool => is_array($item) && ($item['adjustment'] ?? false) !== true));
                $normalized = $this->normalizer->normalize([...$order, 'items' => $items]);
                $unidentified = false;
                foreach ($normalized['items'] as $item) {
                    $unidentified = $unidentified || $item['sku'] === null;
                }
                $shipStation[$number][] = [
                    'id' => (string) $normalized['id'],
                    'status' => $normalized['status'],
                    'skus' => $normalized['sku_quantities'],
                    'unidentified_lines' => $unidentified,
                    'has_customs' => is_array($order['internationalOptions']['customsItems'] ?? null) && $order['internationalOptions']['customsItems'] !== [],
                    'address' => $normalized['shipping_address'],
                ];
            }
        }
        $remaining = $shipStation === [] ? [] : $this->shopify->preShipmentRemainingItems($store, array_map('strval', array_keys($shipStation)));

        return [
            'rows' => $this->analyzer->analyze($shipStation, $remaining),
            'total' => $total,
            'checked' => count($shipStation),
            'shipstation_read_at' => $shipStationReadAt,
            'shopify_read_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }
}
