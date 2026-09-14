<?php

declare(strict_types=1);

use App\Domain\Reports\CatalogQualityAnalyzer;
use PHPUnit\Framework\TestCase;

final class CatalogQualityParityTest extends TestCase
{
    /**
     * Flags a product not published to Online Store, missing SEO
     * title/description, or not in any collection -- a product can carry
     * several of these at once. `published` (legacy, REST) and the
     * presence of `onlineStoreUrl` (Laravel, GraphQL) represent the same
     * "is this live on the storefront" concept through different API
     * shapes -- confirmed against the product query, which explicitly
     * fetches `onlineStoreUrl` for this purpose.
     */
    public function test_rows_match_legacy(): void
    {
        $legacyProducts = [
            // every issue at once
            $this->legacyProduct(1, published: false, seoTitle: '', seoDescription: '', collectionCount: 0),
            // published, has SEO, in a collection -> no issues, no row
            $this->legacyProduct(2, published: true, seoTitle: 'Great Widget', seoDescription: 'Buy it now', collectionCount: 1),
            // only missing SEO description
            $this->legacyProduct(3, published: true, seoTitle: 'Gadget', seoDescription: '', collectionCount: 2),
        ];
        $legacyMethod = new ReflectionMethod(\ProductInventoryPageLoader::class, 'buildCatalogQualityRows');
        $legacyRows = $legacyMethod->invoke(null, $legacyProducts);

        $laravelProducts = [
            $this->laravelProduct(1, published: false, seoTitle: '', seoDescription: '', collectionCount: 0),
            $this->laravelProduct(2, published: true, seoTitle: 'Great Widget', seoDescription: 'Buy it now', collectionCount: 1),
            $this->laravelProduct(3, published: true, seoTitle: 'Gadget', seoDescription: '', collectionCount: 2),
        ];
        $laravelRows = (new CatalogQualityAnalyzer())->analyze($laravelProducts);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function legacyProduct(int $id, bool $published, string $seoTitle, string $seoDescription, int $collectionCount): array
    {
        return ['id' => $id, 'title' => "Product {$id}", 'vendor' => 'Acme', 'product_type' => 'Gadgets', 'published' => $published, 'seo_title' => $seoTitle, 'seo_description' => $seoDescription, 'collection_count' => $collectionCount];
    }

    /** @return array<string, mixed> */
    private function laravelProduct(int $id, bool $published, string $seoTitle, string $seoDescription, int $collectionCount): array
    {
        return [
            'legacyResourceId' => $id,
            'title' => "Product {$id}",
            'vendor' => 'Acme',
            'productType' => 'Gadgets',
            'onlineStoreUrl' => $published ? "https://example.com/products/{$id}" : null,
            'seo' => ['title' => $seoTitle, 'description' => $seoDescription],
            'collections' => ['nodes' => array_fill(0, $collectionCount, ['id' => 'gid://shopify/Collection/1'])],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{title: mixed, issues: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'title' => $r['title'],
            'issues' => $r['issues'],
        ], $rows);
    }
}
