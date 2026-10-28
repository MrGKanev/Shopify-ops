<?php

namespace Tests\Support;

/** Payload builders for the Shopify seam, shared so contract changes touch one file. */
class ShopifyFixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array{name: string, created_at: string, cancelled: bool, test: bool, customer_id: string, skus: list<string>, lines_complete: bool}
     */
    public static function historyOrder(string $name, string $at, array $overrides = []): array
    {
        return $overrides + ['name' => $name, 'created_at' => $at, 'cancelled' => false, 'test' => false, 'customer_id' => 'c1', 'skus' => [], 'lines_complete' => true];
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool}
     */
    public static function history(array $orders, bool $truncated = false, int $pages = 1): array
    {
        return ['orders' => $orders, 'pages' => $pages, 'truncated' => $truncated];
    }
}
