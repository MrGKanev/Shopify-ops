<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyFulfillmentSlaCandidatesTest extends TestCase
{
    public function test_query_fetches_paid_orders_with_fulfillment_shipping_and_region_data(): void
    {
        $node = ['legacyResourceId' => '1', 'name' => '#1001', 'createdAt' => '2026-06-01', 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'FULFILLED', 'shippingAddress' => ['provinceCode' => 'MA', 'countryCodeV2' => 'US'], 'shippingLines' => ['nodes' => [['title' => 'Express']]], 'lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/1', 'title' => 'Grinder', 'sku' => 'zerno-z1-black', 'quantity' => 1, 'vendor' => 'Zerno']]], 'fulfillments' => [['createdAt' => '2026-06-05', 'status' => 'SUCCESS']]];
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => $node]]]]])]);

        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->fulfillmentSlaCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-06-01', '2026-06-30');

        $this->assertSame('2026-06-05', $result['orders'][0]['fulfillments'][0]['created_at']);
        $this->assertSame('Express', $result['orders'][0]['shipping_lines'][0]['title']);
        $this->assertSame('Zerno', $result['orders'][0]['line_items'][0]['vendor']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any financial_status:paid created_at:>=2026-06-01T00:00:00Z created_at:<=2026-06-30T23:59:59Z' && str_contains((string) $request['query'], 'fulfillments(first: 250)'));
    }
}
