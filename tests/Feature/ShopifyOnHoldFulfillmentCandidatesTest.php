<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyOnHoldFulfillmentCandidatesTest extends TestCase
{
    public function test_query_fetches_on_hold_fulfillment_orders_and_filters_dates_inclusively(): void
    {
        $node = fn (string $date): array => ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'ON_HOLD', 'order' => ['legacyResourceId' => '1', 'name' => '#1', 'createdAt' => $date, 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'ON_HOLD'], 'fulfillmentHolds' => [['reason' => 'MANUAL', 'reasonNotes' => 'Wait']]];
        Http::fake(['*' => Http::response(['data' => ['fulfillmentOrders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => $node('2026-06-01T00:00:00Z')], ['node' => $node('2026-06-30T23:59:00Z')], ['node' => $node('2026-07-01T00:00:00Z')]]]]])]);

        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->onHoldFulfillmentCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-06-01', '2026-06-30');

        $this->assertCount(2, $result['fulfillment_orders']);
        $this->assertSame('MANUAL', $result['fulfillment_orders'][0]['fulfillmentHolds'][0]['reason']);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'query: "status:on_hold"') && str_contains((string) $request['query'], 'fulfillmentHolds'));
    }
}
