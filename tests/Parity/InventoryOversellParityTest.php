<?php

declare(strict_types=1);

use App\Domain\Reports\InventoryOversellAnalyzer;
use PHPUnit\Framework\TestCase;

final class InventoryOversellParityTest extends TestCase
{
    /**
     * Flags a SKU where ShipStation's awaiting-shipment demand exceeds
     * Shopify's tracked stock -- orders that are about to fail to ship.
     * When a SKU maps to more than one product (a `skudupes`-flagged data
     * problem), stock is summed across all of them but the product/variant
     * identity is deliberately left ambiguous rather than silently
     * pointing at whichever one happened to be seen last.
     */
    public function test_rows_match_legacy(): void
    {
        $legacyProducts = [
            $this->legacyProduct(1, 'Widget', [$this->legacyVariant('SHORT-1', 'Default', tracked: true, policy: 'deny', quantity: 5)]),
            // same SKU on a second product -> duplicate_sku, stock summed
            $this->legacyProduct(2, 'Widget Clone', [$this->legacyVariant('SHORT-1', 'Default', tracked: true, policy: 'deny', quantity: 3)]),
            // enough stock -> not flagged
            $this->legacyProduct(3, 'Plenty', [$this->legacyVariant('PLENTY-1', 'Default', tracked: true, policy: 'deny', quantity: 100)]),
            // untracked -> excluded even if awaiting demand exceeds its (irrelevant) quantity
            $this->legacyProduct(4, 'Untracked', [$this->legacyVariant('UNTRACKED-1', 'Default', tracked: false, policy: 'deny', quantity: 0)]),
            // continue-selling policy -> excluded
            $this->legacyProduct(5, 'BackorderOk', [$this->legacyVariant('BACKORDER-1', 'Default', tracked: true, policy: 'continue', quantity: 0)]),
        ];
        $legacySsOrders = [
            $this->ssOrder([$this->ssItem('SHORT-1', 10)]),
            $this->ssOrder([$this->ssItem('PLENTY-1', 5)]),
            $this->ssOrder([$this->ssItem('UNTRACKED-1', 10)]),
            $this->ssOrder([$this->ssItem('BACKORDER-1', 10)]),
        ];
        $legacyMethod = new ReflectionMethod(\ProductInventoryPageLoader::class, 'buildOversellRows');
        $legacyRows = $legacyMethod->invoke(null, $legacyProducts, $legacySsOrders);

        $laravelProducts = [
            $this->laravelProduct(1, 'Widget', [$this->laravelVariant('SHORT-1', 'Default', tracked: true, policy: 'DENY', quantity: 5)]),
            $this->laravelProduct(2, 'Widget Clone', [$this->laravelVariant('SHORT-1', 'Default', tracked: true, policy: 'DENY', quantity: 3)]),
            $this->laravelProduct(3, 'Plenty', [$this->laravelVariant('PLENTY-1', 'Default', tracked: true, policy: 'DENY', quantity: 100)]),
            $this->laravelProduct(4, 'Untracked', [$this->laravelVariant('UNTRACKED-1', 'Default', tracked: false, policy: 'DENY', quantity: 0)]),
            $this->laravelProduct(5, 'BackorderOk', [$this->laravelVariant('BACKORDER-1', 'Default', tracked: true, policy: 'CONTINUE', quantity: 0)]),
        ];
        $laravelRows = (new InventoryOversellAnalyzer())->analyze($laravelProducts, $legacySsOrders);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function legacyProduct(int $id, string $title, array $variants): array
    {
        return ['id' => $id, 'title' => $title, 'variants' => $variants];
    }

    /** @return array<string, mixed> */
    private function legacyVariant(string $sku, string $title, bool $tracked, string $policy, int $quantity): array
    {
        return ['sku' => $sku, 'title' => $title, 'inventory_management' => $tracked ? 'shopify' : '', 'inventory_policy' => $policy, 'inventory_quantity' => $quantity];
    }

    /** @return array<string, mixed> */
    private function laravelProduct(int $id, string $title, array $variants): array
    {
        return ['legacyResourceId' => $id, 'title' => $title, 'variants' => $variants];
    }

    /** @return array<string, mixed> */
    private function laravelVariant(string $sku, string $title, bool $tracked, string $policy, int $quantity): array
    {
        return ['sku' => $sku, 'title' => $title, 'inventoryItem' => ['tracked' => $tracked], 'inventoryPolicy' => $policy, 'inventoryQuantity' => $quantity];
    }

    /** @return array<string, mixed> */
    private function ssOrder(array $items): array
    {
        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    private function ssItem(string $sku, int $quantity): array
    {
        return ['sku' => $sku, 'quantity' => $quantity];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{sku: mixed, stock: mixed, awaiting: mixed, shortfall: mixed, duplicate_sku: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'sku' => $r['sku'],
            'stock' => $r['stock'],
            'awaiting' => $r['awaiting'],
            'shortfall' => $r['shortfall'],
            'duplicate_sku' => $r['duplicate_sku'],
        ], $rows);
    }
}
