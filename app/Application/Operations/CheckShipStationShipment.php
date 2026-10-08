<?php

namespace App\Application\Operations;

use App\Domain\Orders\ShipmentSyncAnalyzer;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class CheckShipStationShipment
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly ShipmentSyncAnalyzer $analyzer, private readonly RaiseOperationalIssue $issues) {}

    public function handle(Store $store, ShipStationClientContract $client, int $orderId, int $shipmentId): void
    {
        if (! $store->shipstation_monitoring_enabled) {
            return;
        }
        $order = $client->getOrder($orderId);
        if ((int) ($order['orderId'] ?? 0) !== $orderId || (int) ($order['advancedOptions']['storeId'] ?? 0) !== (int) $store->store_number) {
            return;
        }
        $number = trim((string) ($order['orderNumber'] ?? ''));
        if ($number === '') {
            throw new \UnexpectedValueException('ShipStation order number is required.');
        }
        $shipments = $client->getOrderShipments($number, true);
        $matches = array_values(array_filter($shipments, fn (array $shipment): bool => (int) ($shipment['orderId'] ?? 0) === $orderId && (int) ($shipment['shipmentId'] ?? 0) === $shipmentId));
        if (count($matches) !== 1) {
            throw new \UnexpectedValueException('ShipStation shipment identity could not be confirmed.');
        }
        $shipment = $matches[0];
        $fingerprint = RaiseOperationalIssue::fingerprint('shipstation_sync', (string) $shipmentId);
        if (($shipment['voided'] ?? false) === true) {
            $this->resolve($store, $fingerprint);

            return;
        }
        $orders = $this->shopify->findByOrderNumber($store, $number);
        $findings = count($orders) === 1 ? $this->analyzer->findings($order, $shipment, $orders[0]) : ['A unique Shopify order could not be found for this shipment.'];
        if ($findings === []) {
            $this->resolve($store, $fingerprint);

            return;
        }
        $this->issues->handle($store, [
            'source_tool' => 'shipstation_sync', 'fingerprint' => $fingerprint, 'reference' => $number,
            'title' => 'ShipStation shipment needs Shopify synchronization review', 'priority' => 'high',
            'payload' => ['order_number' => $number, 'shipstation_order_id' => $orderId, 'shipment_id' => $shipmentId, 'tracking_number' => $shipment['trackingNumber'] ?? null, 'findings' => $findings, 'warning_only' => true],
        ], reopenIgnored: false, countOncePerDay: true);
    }

    private function resolve(Store $store, string $fingerprint): void
    {
        $issue = $store->operationalIssues()->where('fingerprint', $fingerprint)->first();
        if ($issue !== null) {
            $this->issues->resolve($issue);
        }
    }
}
