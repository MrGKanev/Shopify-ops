<?php

declare(strict_types=1);

use App\Domain\Reports\InventoryForecastAnalyzer;
use PHPUnit\Framework\TestCase;

final class InventoryForecastParityTest extends TestCase
{
    /**
     * Projects days-until-zero-stock from 30-day sell-through, so ops can
     * reorder before a variant actually runs out. Sorted ascending with
     * `null` (no depletion risk / nothing to forecast) sorted last.
     */
    public function test_rows_match_legacy(): void
    {
        $legacyOrders = [
            $this->order('#1', [$this->lineItem('FAST-1', 30)]), // sells out fast
            $this->order('#2', [$this->lineItem('SLOW-1', 3)]),
            // cancelled order's sales don't count
            $this->order('#3', [$this->lineItem('FAST-1', 100)], cancelledAt: '2026-01-01T00:00:00Z'),
        ];

        $legacyProducts = [
            // low stock, high recent sales -> depletes fast, sorts first
            $this->legacyProduct(1, 'Fast', [$this->legacyVariant('FAST-1', quantity: 10)]),
            // low stock, some sales -> depletes slower
            $this->legacyProduct(2, 'Slow', [$this->legacyVariant('SLOW-1', quantity: 30)]),
            // zero sales, stock > 30 -> excluded entirely (nothing to forecast)
            $this->legacyProduct(3, 'Plenty', [$this->legacyVariant('PLENTY-1', quantity: 500)]),
            // zero sales, stock <= 30 but stock is 0 -> daily_rate 0, days_to_zero null, excluded
            $this->legacyProduct(4, 'NoSalesZeroStock', [$this->legacyVariant('DEAD-1', quantity: 0)]),
            // untracked -> excluded
            $this->legacyProduct(5, 'Untracked', [$this->legacyVariant('UNTRACKED-1', quantity: 0, tracked: false)]),
        ];
        $legacyMethod = new ReflectionMethod(\ProductInventoryPageLoader::class, 'buildInventoryForecastRows');
        [$legacyRows, $legacyVariantCount] = $legacyMethod->invoke(null, $legacyProducts, $legacyOrders);

        $laravelProducts = [
            $this->laravelProduct(1, 'Fast', [$this->laravelVariant('FAST-1', quantity: 10)]),
            $this->laravelProduct(2, 'Slow', [$this->laravelVariant('SLOW-1', quantity: 30)]),
            $this->laravelProduct(3, 'Plenty', [$this->laravelVariant('PLENTY-1', quantity: 500)]),
            $this->laravelProduct(4, 'NoSalesZeroStock', [$this->laravelVariant('DEAD-1', quantity: 0)]),
            $this->laravelProduct(5, 'Untracked', [$this->laravelVariant('UNTRACKED-1', quantity: 0, tracked: false)]),
        ];
        $laravel = (new InventoryForecastAnalyzer())->analyze($laravelProducts, $legacyOrders);

        $this->assertSame($legacyVariantCount, $laravel['variants']);
        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravel['rows']));
    }

    /** @return array<string, mixed> */
    private function order(string $name, array $lineItems, ?string $cancelledAt = null): array
    {
        $order = ['name' => $name, 'line_items' => $lineItems];
        if ($cancelledAt !== null) {
            $order['cancelled_at'] = $cancelledAt;
        }

        return $order;
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
    private function legacyVariant(string $sku, int $quantity, bool $tracked = true, string $policy = 'deny'): array
    {
        return ['sku' => $sku, 'title' => 'Default', 'inventory_management' => $tracked ? 'shopify' : '', 'inventory_policy' => $policy, 'inventory_quantity' => $quantity];
    }

    /** @return array<string, mixed> */
    private function laravelProduct(int $id, string $title, array $variants): array
    {
        return ['legacyResourceId' => $id, 'title' => $title, 'variants' => $variants];
    }

    /** @return array<string, mixed> */
    private function laravelVariant(string $sku, int $quantity, bool $tracked = true, string $policy = 'DENY'): array
    {
        return ['sku' => $sku, 'title' => 'Default', 'inventoryItem' => ['tracked' => $tracked], 'inventoryPolicy' => $policy, 'inventoryQuantity' => $quantity];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{sku: mixed, stock: mixed, sold_30d: mixed, daily_rate: mixed, days_to_zero: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'sku' => $r['sku'],
            'stock' => $r['stock'],
            'sold_30d' => $r['sold_30d'],
            'daily_rate' => $r['daily_rate'],
            'days_to_zero' => $r['days_to_zero'],
        ], $rows);
    }
}
