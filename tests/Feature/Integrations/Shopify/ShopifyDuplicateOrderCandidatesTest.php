<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyDuplicateOrderCandidatesTest extends TestCase
{
    public function test_range_query_normalizes_order_fields_and_preserves_legacy_page_limit(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '42', 'name' => '#1', 'createdAt' => '2026-09-02T10:00:00Z', 'email' => 'a@x.com', 'displayFinancialStatus' => 'PAID', 'totalPriceSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']]]]]]]])]);

        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->duplicateOrderCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-09-01', '2026-09-07');

        $this->assertSame(42, $result['orders'][0]['id']);
        $this->assertSame('50.00', $result['orders'][0]['total_price']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-07T23:59:59Z' && str_contains((string) $request['query'], 'reverse: false'));
    }
}
