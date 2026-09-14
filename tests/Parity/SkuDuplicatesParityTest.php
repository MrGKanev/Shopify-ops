<?php

declare(strict_types=1);

use App\Domain\Reports\SkuDuplicatesAnalyzer;
use PHPUnit\Framework\TestCase;

final class SkuDuplicatesParityTest extends TestCase
{
    /**
     * Flags a SKU shared by 2+ variants across the catalog -- a data-entry
     * error that can cause the wrong item to ship or inventory to
     * double-count. Blank SKUs are excluded (a documented rule), and the
     * `totalVariants` count includes every variant scanned, not just the
     * duplicated ones.
     */
    public function test_rows_match_legacy(): void
    {
        $legacyProducts = [
            $this->legacyProduct(1, 'Widget', 'active', [$this->variant('DUP-1', 'Red'), $this->variant('', 'Blue')]),
            $this->legacyProduct(2, 'Gadget', 'draft', [$this->variant('DUP-1', 'Small')]),
            $this->legacyProduct(3, 'Gizmo', 'active', [$this->variant('UNIQUE-1', 'Default')]),
        ];
        $legacyMethod = new ReflectionMethod(\ProductInventoryPageLoader::class, 'buildSkuDupeRows');
        [$legacyRows, $legacyTotalVariants] = $legacyMethod->invoke(null, $legacyProducts);

        $laravelProducts = [
            $this->laravelProduct(1, 'Widget', 'ACTIVE', [$this->variant('DUP-1', 'Red'), $this->variant('', 'Blue')]),
            $this->laravelProduct(2, 'Gadget', 'DRAFT', [$this->variant('DUP-1', 'Small')]),
            $this->laravelProduct(3, 'Gizmo', 'ACTIVE', [$this->variant('UNIQUE-1', 'Default')]),
        ];
        $laravel = (new SkuDuplicatesAnalyzer())->analyze($laravelProducts);

        $this->assertSame($legacyTotalVariants, $laravel['totalVariants']);
        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravel['rows']));
    }

    /** @return array<string, mixed> */
    private function legacyProduct(int $id, string $title, string $status, array $variants): array
    {
        return ['id' => $id, 'title' => $title, 'status' => $status, 'variants' => $variants];
    }

    /** @return array<string, mixed> */
    private function laravelProduct(int $id, string $title, string $status, array $variants): array
    {
        return ['legacyResourceId' => $id, 'title' => $title, 'status' => $status, 'variants' => $variants];
    }

    /** @return array<string, mixed> */
    private function variant(string $sku, string $title): array
    {
        return ['sku' => $sku, 'title' => $title];
    }

    /**
     * `product_status` is compared case-insensitively: legacy echoes the
     * raw REST status (already lowercase, e.g. `"active"`), Laravel
     * lowercases the GraphQL enum (`"ACTIVE"` -> `"active"`) -- a casing
     * adaptation for a different data source, not a logic difference.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{sku: mixed, count: mixed, product_ids: mixed, statuses: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'sku' => $r['sku'],
            'count' => $r['count'],
            'product_ids' => array_column($r['variants'], 'product_id'),
            'statuses' => array_map('strtolower', array_column($r['variants'], 'product_status')),
        ], $rows);
    }
}
