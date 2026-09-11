<?php

namespace App\Integrations\ShipStation;

interface ShipStationClientContract
{
    public function healthCheck(): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getOrderShipments(string $orderNumber): array;

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
