<?php

namespace App\Application\Orders;

use App\Domain\Orders\OrderChannelComparator;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\ShipStation\ShipStationOrderNormalizer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class LookupOrder
{
    public function __construct(
        private readonly ShopifyOrders $shopify,
        private readonly ShipStationClientFactory $shipStationClients,
        private readonly ShipStationOrderNormalizer $shipStationOrderNormalizer,
        private readonly OrderChannelComparator $comparator,
        private readonly FindStoreShipStationOrders $findOrders,
        private readonly LoadStoreShipStationShipments $loadShipments,
    ) {}

    public function handle(Store $store, string $orderNumber): OrderLookupResult
    {
        $shopifyOrders = $this->shopify->findByOrderNumber($store, $orderNumber);
        $shipStation = $this->shipStationClients->forStore($store);
        $shipStationOrders = $shipStation === null ? [] : $this->findOrders->handle($store, $shipStation, $orderNumber);
        $shipStationShipments = $shipStation === null ? [] : $this->loadShipments->handle($store, $shipStation, $orderNumber, $shipStationOrders);
        $normalizedShipStationOrders = $this->normalizeShipStationOrders($shipStationOrders, $shipStationShipments);
        $comparisonState = $this->comparisonState($shopifyOrders, $normalizedShipStationOrders, $shipStation !== null);
        $comparison = $comparisonState === 'ready'
            ? $this->comparator->compare($shopifyOrders[0], $normalizedShipStationOrders[0])
            : null;

        return new OrderLookupResult(
            orderNumber: $orderNumber,
            shopifyOrders: $shopifyOrders,
            shipStationOrders: $normalizedShipStationOrders,
            shipStationShipments: $shipStationShipments,
            shipStationConfigured: $shipStation !== null,
            comparisonState: $comparisonState,
            comparison: $comparison,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @param  list<array<string, mixed>>  $shipments
     * @return list<array<string, mixed>>
     */
    private function normalizeShipStationOrders(array $orders, array $shipments): array
    {
        return array_map(function (array $order) use ($shipments): array {
            $orderId = (string) ($order['orderId'] ?? '');
            $matchingShipments = array_values(array_filter(
                $shipments,
                fn (array $shipment): bool => (string) ($shipment['orderId'] ?? '') === $orderId,
            ));

            return $this->shipStationOrderNormalizer->normalize($order, $matchingShipments);
        }, $orders);
    }

    /**
     * @param  list<array<string, mixed>>  $shopifyOrders
     * @param  list<array<string, mixed>>  $shipStationOrders
     */
    private function comparisonState(array $shopifyOrders, array $shipStationOrders, bool $shipStationConfigured): string
    {
        if (! $shipStationConfigured) {
            return 'not_configured';
        }

        if ($shopifyOrders === []) {
            return 'shopify_missing';
        }

        if ($shipStationOrders === []) {
            return 'shipstation_missing';
        }

        if (count($shopifyOrders) !== 1 || count($shipStationOrders) !== 1) {
            return 'ambiguous';
        }

        return 'ready';
    }
}
