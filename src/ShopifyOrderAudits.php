<?php
declare(strict_types=1);

require_once __DIR__ . '/ShopifyOrderQueryAudits.php';
require_once __DIR__ . '/ShopifyOrderEventAudits.php';

/**
 * Backward-compatible facade for Shopify order audit workflows.
 */
class ShopifyOrderAudits
{
    private readonly ShopifyOrderQueryAudits $queryAudits;
    private readonly ShopifyOrderEventAudits $eventAudits;

    public function __construct(ShopifyOrderFetcher $orders)
    {
        $this->queryAudits = new ShopifyOrderQueryAudits($orders);
        $this->eventAudits = new ShopifyOrderEventAudits($orders);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddressScan(string $startDate, string $endDate, bool $unfulfilledOnly = false): array
    {
        return $this->queryAudits->fetchOrdersForAddressScan($startDate, $endDate, $unfulfilledOnly);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForHighValue(string $startDate, string $endDate): array
    {
        return $this->queryAudits->fetchOrdersForHighValue($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersWithAddressChanges(string $startDate, string $endDate): array
    {
        return $this->eventAudits->fetchOrdersWithAddressChanges($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchEditedOrders(string $startDate, string $endDate): array
    {
        return $this->eventAudits->fetchEditedOrders($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchRefundedOrders(string $startDate, string $endDate): array
    {
        return $this->queryAudits->fetchRefundedOrders($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForCountryMismatch(string $startDate, string $endDate): array
    {
        return $this->queryAudits->fetchOrdersForCountryMismatch($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchPartiallyFulfilledOrders(string $startDate, string $endDate): array
    {
        return $this->queryAudits->fetchPartiallyFulfilledOrders($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchFulfilledOrdersWithTracking(string $startDate, string $endDate): array
    {
        return $this->queryAudits->fetchFulfilledOrdersWithTracking($startDate, $endDate);
    }

    /**
     * @return array<int, array{order: array, changed_at: string, fulfillment_at: string}>
     */
    public function fetchPostShipAddressChanges(string $startDate, string $endDate): array
    {
        return $this->eventAudits->fetchPostShipAddressChanges($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersWithNotes(string $startDate, string $endDate): array
    {
        return $this->queryAudits->fetchOrdersWithNotes($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddrDupes(string $startDate, string $endDate): array
    {
        return $this->queryAudits->fetchOrdersForAddrDupes($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForSla(string $startDate, string $endDate): array
    {
        return $this->queryAudits->fetchOrdersForSla($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchCancelledOrders(string $startDate, string $endDate): array
    {
        return $this->queryAudits->fetchCancelledOrders($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForDiscountAudit(string $startDate, string $endDate): array
    {
        return $this->queryAudits->fetchOrdersForDiscountAudit($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForTagPolicy(string $startDate, string $endDate): array
    {
        return $this->queryAudits->fetchOrdersForTagPolicy($startDate, $endDate);
    }
}
