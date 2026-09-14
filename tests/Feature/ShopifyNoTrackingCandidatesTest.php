<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyNoTrackingCandidatesTest extends TestCase
{
    public function test_query_uses_updated_date_and_returns_only_fulfilled_statuses_with_tracking_data(): void
    {
        $node = fn (string $status) => ['legacyResourceId' => '1', 'name' => '#1', 'createdAt' => '2025-01-01', 'displayFulfillmentStatus' => $status, 'fulfillments' => [['id' => 'gid://shopify/Fulfillment/1', 'createdAt' => '2026-06-05', 'trackingInfo' => [['company' => 'UPS', 'number' => '']]]]];
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => $node('FULFILLED')], ['node' => $node('UNFULFILLED')]]]]])]);
        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->noTrackingCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-06-01');
        $this->assertCount(1, $result['orders']);
        $this->assertSame('UPS', $result['orders'][0]['fulfillments'][0]['tracking_company']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any updated_at:>=2026-06-01T00:00:00Z' && str_contains((string) $request['query'], 'trackingInfo'));
    }
}
