<?php
declare(strict_types=1);

require_once __DIR__ . '/ShopifyOrderLookup.php';
require_once __DIR__ . '/ShopifyCustomDataLookups.php';
require_once __DIR__ . '/ShopifyOrderInsights.php';

/**
 * Facade for non-audit Shopify Admin lookups and order searches.
 */
class ShopifyAdminLookups
{
    private readonly ShopifyOrderLookup $orders;
    private readonly ShopifyCustomDataLookups $customData;
    private readonly ShopifyOrderInsights $insights;

    public function __construct(
        ShopifyGraphQLClient $client,
        ?Cache $cache = null
    ) {
        $this->orders     = new ShopifyOrderLookup($client, $cache);
        $this->customData = new ShopifyCustomDataLookups($client);
        $this->insights   = new ShopifyOrderInsights($client);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber): array
    {
        return $this->orders->findByOrderNumber($orderNumber);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        return $this->orders->getOrder($orderId);
    }

    public function isOnHold(string $orderId): bool
    {
        return $this->orders->isOnHold($orderId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchMetafieldDefinitions(string $ownerType = 'ORDER'): array
    {
        return $this->customData->fetchMetafieldDefinitions($ownerType);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrderMetafields(string $orderId): array
    {
        return $this->customData->getOrderMetafields($orderId);
    }

    /**
     * @return array{matches: array, scanned: int, pages: int, truncated: bool}
     */
    public function searchOrdersByTag(
        string $tag,
        string $startDate = '',
        string $endDate = '',
        int $maxPages = 20
    ): array {
        return $this->insights->searchOrdersByTag($tag, $startDate, $endDate, $maxPages);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchOrdersByMetafield(
        string $namespace,
        string $key,
        string $value,
        string $startDate = '',
        string $endDate = '',
        int $maxPages = 10
    ): array {
        return $this->customData->searchOrdersByMetafield($namespace, $key, $value, $startDate, $endDate, $maxPages);
    }

    /**
     * @return array{tags: list<array>, total_orders: int, truncated: bool, pages: int}
     */
    public function fetchTagStats(string $startDate = '', string $endDate = '', int $maxPages = 40): array
    {
        return $this->insights->fetchTagStats($startDate, $endDate, $maxPages);
    }

    /**
     * @return array{orders: array, customer: array|null, total_spent: float, currency: string, truncated: bool}
     */
    public function lookupCustomer(string $email, int $maxPages = 20): array
    {
        return $this->insights->lookupCustomer($email, $maxPages);
    }

    /**
     * @return array{pairs: list<array>, scanned: int, truncated: bool}
     */
    public function findDuplicateOrders(string $startDate, string $endDate): array
    {
        return $this->insights->findDuplicateOrders($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrderEvents(string $orderId): array
    {
        return $this->orders->getOrderEvents($orderId);
    }
}
