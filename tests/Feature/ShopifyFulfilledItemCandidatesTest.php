<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyFulfilledItemCandidatesTest extends TestCase
{
    public function test_query_discovers_updated_old_orders_and_normalizes_fulfilled_quantities(): void
    {
        $node = [
            'legacyResourceId' => '1',
            'name' => '#1',
            'createdAt' => '2025-01-01',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'FULFILLED',
            'fulfillments' => [[
                'id' => 'gid://shopify/Fulfillment/2',
                'createdAt' => '2026-07-10T00:00:00Z',
                'status' => 'SUCCESS',
                'displayStatus' => 'FULFILLED',
                'fulfillmentLineItems' => ['edges' => [[
                    'node' => ['quantity' => 2, 'lineItem' => ['id' => 'gid://shopify/LineItem/3', 'title' => 'Widget', 'name' => 'Widget Blue', 'variantTitle' => 'Blue']],
                ]]],
            ]],
        ];
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => $node]]]]])]);

        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->fulfilledItemCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-07-01');

        $this->assertSame(2, $result['orders'][0]['fulfillments'][0]['line_items'][0]['quantity']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any updated_at:>=2026-07-01T00:00:00Z' && ! str_contains($request['variables']['search'], 'created_at:') && str_contains((string) $request['query'], 'fulfillmentLineItems'));
    }
}
