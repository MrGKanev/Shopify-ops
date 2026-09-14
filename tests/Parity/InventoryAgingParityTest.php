<?php

declare(strict_types=1);

use App\Domain\Reports\InventoryAgingAnalyzer;
use PHPUnit\Framework\TestCase;

final class InventoryAgingParityTest extends TestCase
{
    /**
     * Flags a zero-stock tracked variant that still had recent sales --
     * demand exists but nothing's left to sell. `last_order`/`last_date`
     * track the *most recent* sale (not the first), so the fixture
     * exercises two orders for the same SKU on different dates.
     */
    public function test_rows_match_legacy(): void
    {
        $legacyOrders = [
            $this->order('#1001', '2026-01-05', [$this->lineItem('AGED-1', 2)]),
            $this->order('#1002', '2026-01-10', [$this->lineItem('AGED-1', 3)]),
            $this->order('#1003', '2026-01-01', [$this->lineItem('PLENTY-1', 1)]),
            $this->order('#1004', '2026-01-01', [$this->lineItem('NOSALES-1', 1)]),
        ];

        $legacyProducts = [
            $this->legacyProduct(1, 'Aged', [$this->legacyVariant('AGED-1', tracked: true, policy: 'deny', quantity: 0)]),
            $this->legacyProduct(2, 'Plenty', [$this->legacyVariant('PLENTY-1', tracked: true, policy: 'deny', quantity: 50)]),
            $this->legacyProduct(3, 'Untracked', [$this->legacyVariant('UNTRACKED-1', tracked: false, policy: 'deny', quantity: 0)]),
            $this->legacyProduct(4, 'NoSalesHistory', [$this->legacyVariant('NOHISTORY-1', tracked: true, policy: 'deny', quantity: 0)]),
        ];
        $legacyMethod = new ReflectionMethod(\ProductInventoryPageLoader::class, 'buildInventoryAgingRows');
        [$legacyRows, $legacyVariantCount] = $legacyMethod->invoke(null, $legacyProducts, $legacyOrders);

        $laravelProducts = [
            $this->laravelProduct(1, 'Aged', [$this->laravelVariant('AGED-1', tracked: true, policy: 'DENY', quantity: 0)]),
            $this->laravelProduct(2, 'Plenty', [$this->laravelVariant('PLENTY-1', tracked: true, policy: 'DENY', quantity: 50)]),
            $this->laravelProduct(3, 'Untracked', [$this->laravelVariant('UNTRACKED-1', tracked: false, policy: 'DENY', quantity: 0)]),
            $this->laravelProduct(4, 'NoSalesHistory', [$this->laravelVariant('NOHISTORY-1', tracked: true, policy: 'DENY', quantity: 0)]),
        ];
        $laravel = (new InventoryAgingAnalyzer())->analyze($laravelProducts, $legacyOrders);

        $this->assertSame($legacyVariantCount, $laravel['variants']);
        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravel['rows']));
    }

    /** @return array<string, mixed> */
    private function order(string $name, string $createdAt, array $lineItems): array
    {
        return ['name' => $name, 'created_at' => "{$createdAt}T00:00:00Z", 'line_items' => $lineItems];
    }

    /** @return array<string, mixed> */
    private function lineItem(string $sku, int $quantity): array
    {
        return ['sku' => $sku, 'quantity' => $quantity];
    }

    /** @return array<string, mixed> */
    private function legacyProduct(int $id, string $title, array $variants): array
    {
        return ['id' => $id, 'title' => $title, 'variants' => $variants];
    }

    /** @return array<string, mixed> */
    private function legacyVariant(string $sku, bool $tracked, string $policy, int $quantity): array
    {
        return ['sku' => $sku, 'title' => 'Default', 'inventory_management' => $tracked ? 'shopify' : '', 'inventory_policy' => $policy, 'inventory_quantity' => $quantity];
    }

    /** @return array<string, mixed> */
    private function laravelProduct(int $id, string $title, array $variants): array
    {
        return ['legacyResourceId' => $id, 'title' => $title, 'variants' => $variants];
    }

    /** @return array<string, mixed> */
    private function laravelVariant(string $sku, bool $tracked, string $policy, int $quantity): array
    {
        return ['sku' => $sku, 'title' => 'Default', 'inventoryItem' => ['tracked' => $tracked], 'inventoryPolicy' => $policy, 'inventoryQuantity' => $quantity];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{sku: mixed, recent_qty: mixed, last_order: mixed, last_date: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'sku' => $r['sku'],
            'recent_qty' => $r['recent_qty'],
            'last_order' => $r['last_order'],
            'last_date' => $r['last_date'],
        ], $rows);
    }
}
