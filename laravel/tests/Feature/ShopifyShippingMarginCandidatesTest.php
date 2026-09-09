<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyShippingMarginCandidatesTest extends TestCase
{
    public function test_query_uses_update_window_and_normalizes_shipping_prices(): void
    {
        $node = ['legacyResourceId' => '1', 'name' => '#1001', 'createdAt' => '2025-01-01', 'email' => 'a@example.com', 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'FULFILLED', 'totalPriceSet' => ['shopMoney' => ['amount' => '90.00', 'currencyCode' => 'USD']], 'shippingLines' => ['nodes' => [['title' => 'Ground', 'originalPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']]]]]];
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => $node]]]]])]);

        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->shippingMarginCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-06-01');

        $this->assertSame('10.00', $result['orders'][0]['shipping_lines'][0]['price']);
        Http::assertSent(fn (Request $request): bool => str_contains($request['variables']['search'], 'updated_at:>=2026-06-01T00:00:00Z') && ! str_contains($request['variables']['search'], 'created_at:') && str_contains((string) $request['query'], 'shippingLines(first: 250)'));
    }
}
