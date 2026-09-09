<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyItemMismatchCandidatesTest extends TestCase
{
    public function test_query_fetches_all_statuses_with_line_items_for_inclusive_range(): void
    {
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '1', 'name' => '#1', 'createdAt' => '2026-06-01', 'displayFinancialStatus' => 'PENDING', 'shippingLines' => ['nodes' => []], 'lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/1', 'sku' => 'A', 'quantity' => 2]]]]]]]]])]);
        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->itemMismatchCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-06-01', '2026-06-30');
        $this->assertSame('A', $result['orders'][0]['line_items'][0]['sku']);
        $this->assertSame([], $result['orders'][0]['shipping_lines']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any created_at:>=2026-06-01T00:00:00Z created_at:<=2026-06-30T23:59:59Z' && str_contains((string) $request['query'], 'shippingLines(first: 1)'));
    }
}
