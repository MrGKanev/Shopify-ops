<?php

declare(strict_types=1);

use App\Domain\Reports\ZombieProductsAnalyzer;
use PHPUnit\Framework\TestCase;

final class ZombieProductsParityTest extends TestCase
{
    /**
     * Flags a product nobody can buy: no variants at all, or every tracked
     * variant sitting at zero stock. `inventory_management === ''`
     * (legacy's REST-shaped "is this variant tracked" check) and
     * `inventoryItem.tracked !== true` (Laravel's GraphQL-shaped
     * equivalent) are different fields from different APIs for the same
     * underlying concept -- confirmed against `ShopifyAdminClient`'s
     * product query, which explicitly fetches `inventoryItem { tracked }`
     * for this exact purpose -- so each side gets its own appropriately-
     * shaped fixture rather than one shared shape.
     */
    public function test_rows_match_legacy(): void
    {
        $legacyProducts = [
            $this->legacyProduct(1, 'Empty', []),
            $this->legacyProduct(2, 'AllZero', [
                $this->legacyVariant(tracked: true, policy: 'deny', quantity: 0),
                $this->legacyVariant(tracked: true, policy: 'deny', quantity: 0),
            ]),
            $this->legacyProduct(3, 'Mixed', [
                $this->legacyVariant(tracked: true, policy: 'deny', quantity: 5),
                $this->legacyVariant(tracked: true, policy: 'deny', quantity: 0),
            ]),
            $this->legacyProduct(4, 'UntrackedOnly', [
                $this->legacyVariant(tracked: false, policy: 'deny', quantity: 0),
            ]),
            $this->legacyProduct(5, 'ContinuePolicyIgnored', [
                $this->legacyVariant(tracked: true, policy: 'continue', quantity: 0),
            ]),
        ];
        $legacyMethod = new ReflectionMethod(\ProductInventoryPageLoader::class, 'buildZombieProductRows');
        $legacyRows = $legacyMethod->invoke(null, $legacyProducts);

        $laravelProducts = [
            $this->laravelProduct(1, 'Empty', []),
            $this->laravelProduct(2, 'AllZero', [
                $this->laravelVariant(tracked: true, policy: 'DENY', quantity: 0),
                $this->laravelVariant(tracked: true, policy: 'DENY', quantity: 0),
            ]),
            $this->laravelProduct(3, 'Mixed', [
                $this->laravelVariant(tracked: true, policy: 'DENY', quantity: 5),
                $this->laravelVariant(tracked: true, policy: 'DENY', quantity: 0),
            ]),
            $this->laravelProduct(4, 'UntrackedOnly', [
                $this->laravelVariant(tracked: false, policy: 'DENY', quantity: 0),
            ]),
            $this->laravelProduct(5, 'ContinuePolicyIgnored', [
                $this->laravelVariant(tracked: true, policy: 'CONTINUE', quantity: 0),
            ]),
        ];
        $laravelRows = (new ZombieProductsAnalyzer())->analyze($laravelProducts);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function legacyProduct(int $id, string $title, array $variants): array
    {
        return ['id' => $id, 'title' => $title, 'vendor' => 'Acme', 'product_type' => 'Gadgets', 'variants' => $variants];
    }

    /** @return array<string, mixed> */
    private function legacyVariant(bool $tracked, string $policy, int $quantity): array
    {
        return ['inventory_management' => $tracked ? 'shopify' : '', 'inventory_policy' => $policy, 'inventory_quantity' => $quantity];
    }

    /** @return array<string, mixed> */
    private function laravelProduct(int $id, string $title, array $variants): array
    {
        return ['legacyResourceId' => $id, 'title' => $title, 'vendor' => 'Acme', 'productType' => 'Gadgets', 'variants' => $variants];
    }

    /** @return array<string, mixed> */
    private function laravelVariant(bool $tracked, string $policy, int $quantity): array
    {
        return ['inventoryItem' => ['tracked' => $tracked], 'inventoryPolicy' => $policy, 'inventoryQuantity' => $quantity];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{title: mixed, reason: mixed, detail: mixed, stock: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'title' => $r['title'],
            'reason' => $r['reason'],
            'detail' => $r['detail'],
            'stock' => $r['stock'],
        ], $rows);
    }
}
