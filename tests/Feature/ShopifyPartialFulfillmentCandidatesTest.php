<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyPartialFulfillmentCandidatesTest extends TestCase
{
    public function test_query_fetches_only_partial_orders_with_remaining_quantities(): void
    {
        $partial = ['legacyResourceId' => '1', 'name' => '#1001', 'createdAt' => '2026-06-01', 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED', 'lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/1', 'title' => 'Grinder', 'sku' => 'G-1', 'quantity' => 2, 'unfulfilledQuantity' => 1]]], 'fulfillments' => [['createdAt' => '2026-06-05', 'status' => 'SUCCESS']]];
        $fulfilled = [...$partial, 'legacyResourceId' => '2', 'displayFulfillmentStatus' => 'FULFILLED'];
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => $partial], ['node' => $fulfilled]]]]])]);

        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->partialFulfillmentCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-06-01', '2026-06-30');

        $this->assertCount(1, $result['orders']);
        $this->assertSame(1, $result['orders'][0]['line_items'][0]['fulfillable_quantity']);
        $this->assertSame('2026-06-05', $result['orders'][0]['fulfillments'][0]['created_at']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:open -financial_status:refunded fulfillment_status:partial created_at:>=2026-06-01T00:00:00Z created_at:<=2026-06-30T23:59:59Z' && str_contains((string) $request['query'], 'unfulfilledQuantity'));
    }
}
