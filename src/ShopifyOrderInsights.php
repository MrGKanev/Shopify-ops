<?php
declare(strict_types=1);

require_once __DIR__ . '/ShopifyOrderTagInsights.php';
require_once __DIR__ . '/ShopifyCustomerOrderInsights.php';
require_once __DIR__ . '/ShopifyDuplicateOrderInsights.php';

/**
 * Backward-compatible facade for Shopify order insight workflows.
 */
class ShopifyOrderInsights
{
    private readonly ShopifyOrderTagInsights $tagInsights;
    private readonly ShopifyCustomerOrderInsights $customerInsights;
    private readonly ShopifyDuplicateOrderInsights $duplicateInsights;

    public function __construct(ShopifyGraphQLClient $client)
    {
        $this->tagInsights       = new ShopifyOrderTagInsights($client);
        $this->customerInsights  = new ShopifyCustomerOrderInsights($client);
        $this->duplicateInsights = new ShopifyDuplicateOrderInsights($client);
    }

    /**
     * @return array{matches: array, scanned: int, pages: int, truncated: bool}
     */
    public function searchOrdersByTag(
        string $tag,
        string $startDate = '',
        string $endDate   = '',
        int    $maxPages  = 20
    ): array {
        return $this->tagInsights->searchOrdersByTag($tag, $startDate, $endDate, $maxPages);
    }

    /**
     * @return array{tags: list<array>, total_orders: int, truncated: bool, pages: int}
     */
    public function fetchTagStats(string $startDate = '', string $endDate = '', int $maxPages = 40): array
    {
        return $this->tagInsights->fetchTagStats($startDate, $endDate, $maxPages);
    }

    /**
     * @return array{orders: array, customer: array|null, total_spent: float, currency: string, truncated: bool}
     */
    public function lookupCustomer(string $email, int $maxPages = 20): array
    {
        return $this->customerInsights->lookupCustomer($email, $maxPages);
    }

    /**
     * @return array{pairs: list<array>, scanned: int, truncated: bool}
     */
    public function findDuplicateOrders(string $startDate, string $endDate): array
    {
        return $this->duplicateInsights->findDuplicateOrders($startDate, $endDate);
    }
}
