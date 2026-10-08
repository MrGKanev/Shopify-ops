<?php

namespace App\Integrations\ShipStation;

interface ShipStationClientContract
{
    /** @return list<array<string, mixed>> */
    public function webhookSubscriptions(): array;

    public function subscribeWebhook(string $url, string $topic, int $storeId): int;

    public function unsubscribeWebhook(int $id): void;

    /** @return list<array<string, mixed>> */
    public function webhookResource(string $url, string $topic, int $storeId): array;

    /** @return list<array<string, mixed>> */
    public function recentMonitoringShipments(int $storeId, string $since): array;

    /** @return array<string, mixed> */
    public function getOrder(int $orderId): array;

    /** @param array<string, mixed> $payload
     * @return array<string, mixed> */
    public function updateOrder(array $payload): array;

    public function holdOrder(int $orderId, string $holdUntil): void;

    public function restoreOrder(int $orderId): void;

    public function addOrderTag(int $orderId, int $tagId): void;

    public function refreshStore(int $storeId): void;

    /** @param array<string, mixed> $request
     * @return list<array<string, mixed>> */
    public function getRates(array $request): array;

    /** @return array<string, mixed> */
    public function getWarehouse(int $warehouseId): array;

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function customsProducts(): array;

    public function healthCheck(): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber, ?int $shipStationStoreId = null): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getOrderShipments(string $orderNumber, bool $includeItems = false): array;

    /** @return list<array<string, mixed>> */
    public function getOrderCostShipments(int $orderId, int $storeId, string $startDate, string $endDate): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAllOrders(string $startDate, string $endDate): array;

    /** @return list<array<string, mixed>> */
    public function fetchAwaitingOrders(): array;

    /** @return list<array<string, mixed>> */
    public function fetchActiveOrders(): array;

    /** @return list<array<string, mixed>> */
    public function fetchShipmentsByDate(string $startDate, string $endDate): array;

    /** @return list<array<string, mixed>> */
    public function fetchVoidedShipments(string $startDate, string $endDate): array;

    /**
     * @param  array<string, mixed>  $shopifyOrder
     * @return array<string, mixed>
     */
    public function buildOrderPayload(array $shopifyOrder): array;

    /**
     * Creates the order in ShipStation from a normalized Shopify order.
     *
     * @param  array<string, mixed>  $shopifyOrder
     * @return array<string, mixed> the created ShipStation order
     */
    public function createOrder(array $shopifyOrder): array;
}
