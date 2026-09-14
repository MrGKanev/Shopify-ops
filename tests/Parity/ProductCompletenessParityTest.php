<?php

declare(strict_types=1);

use App\Domain\Reports\ProductCompletenessAnalyzer;
use PHPUnit\Framework\TestCase;

final class ProductCompletenessParityTest extends TestCase
{
    /**
     * `ProductCompletenessAnalyzer` is a GraphQL-shaped rewrite, not a
     * line-for-line port: legacy reads REST-style `images`/`body_html`
     * fields, Laravel reads GraphQL `images.nodes`/`descriptionHtml` —
     * different source shapes because the underlying fetch query is
     * different on each side (out of this harness's scope). Both sides
     * fetch only `status:active` products by default (confirmed by
     * reading `src/Shopify.php::fetchAllProducts()`'s default parameter
     * and `ShopifyAdminClient::catalogueCandidates($store, 'status:active')`),
     * which is why Laravel's row omits `status` at all — it would always
     * read "active" on both sides, so this isn't a live gap despite the
     * field being absent from the output shape.
     *
     * Rather than asserting full row equality (which the differing
     * fetch/rule shapes make an apples-to-oranges comparison), this test
     * locks in the one thing that has to actually agree: a product with
     * some (not all) variants missing a SKU is flagged critical with the
     * same "N of M variants missing SKU" count on both sides.
     *
     * Two things confirmed as *deliberate* Laravel-only enhancements, not
     * bugs, while reading the code — documented in `parity-verification.md`
     * rather than fixed or flagged open, since they don't remove or weaken
     * any legacy behavior, they add stricter/additional detection:
     * - Laravel flags a product with zero variants at all as critical
     *   ("No variants"); legacy's loop never runs for zero variants, so
     *   `missingSkuCount` stays 0 and nothing is flagged.
     * - Laravel's description check `html_entity_decode()`s and strips
     *   unicode whitespace (`\x{00A0}`/`\x{200B}`) before checking
     *   emptiness; legacy's plain `trim(strip_tags(...))` would treat a
     *   description of literal `&nbsp;` as non-empty text.
     * - Laravel adds a severity+title+id sort; legacy returns products in
     *   fetch order with no sort at all.
     */
    public function test_missing_sku_detection_matches_legacy(): void
    {
        $legacyProduct = [
            'id' => 1,
            'title' => 'Widget',
            'vendor' => 'Acme',
            'product_type' => 'Gadgets',
            'status' => 'active',
            'images' => [['id' => 'img1']],
            'body_html' => '<p>A real description.</p>',
            'variants' => [
                ['sku' => 'WIDGET-1'],
                ['sku' => ''],
                ['sku' => ''],
            ],
        ];
        $legacyMethod = new ReflectionMethod(\ProductInventoryPageLoader::class, 'buildProductCheckRows');
        $legacyRows = $legacyMethod->invoke(null, [$legacyProduct]);

        $laravelProduct = [
            'legacyResourceId' => 1,
            'title' => 'Widget',
            'vendor' => 'Acme',
            'productType' => 'Gadgets',
            'status' => 'ACTIVE',
            'images' => ['nodes' => [['id' => 'gid://shopify/ProductImage/1']]],
            'descriptionHtml' => '<p>A real description.</p>',
            'variants' => [
                ['sku' => 'WIDGET-1'],
                ['sku' => ''],
                ['sku' => ''],
            ],
        ];
        $laravelRows = (new ProductCompletenessAnalyzer())->analyze([$laravelProduct]);

        $this->assertCount(1, $legacyRows);
        $this->assertCount(1, $laravelRows);
        $this->assertSame('critical', $legacyRows[0]['severity']);
        $this->assertSame('critical', $laravelRows[0]['severity']);
        $legacySkuIssue = current(array_filter($legacyRows[0]['issues'], fn (array $i): bool => str_contains($i['message'], 'missing SKU')));
        $laravelSkuIssue = current(array_filter($laravelRows[0]['issues'], fn (array $i): bool => str_contains($i['message'], 'missing SKU')));
        $this->assertSame('2 of 3 variants missing SKU', $legacySkuIssue['message']);
        $this->assertSame('2 of 3 variants missing SKU', $laravelSkuIssue['message']);
    }
}
