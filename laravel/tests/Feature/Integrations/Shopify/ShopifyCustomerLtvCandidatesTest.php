<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyCustomerLtvCandidatesTest extends TestCase
{
    public function test_query_includes_all_statuses_and_normalizes_cancellation(): void
    {
        Http::preventStrayRequests();
        $first = ['legacyResourceId' => '42', 'name' => '#1', 'createdAt' => '2026-09-02', 'cancelledAt' => '2026-09-03', 'email' => 'a@x.com', 'totalPriceSet' => ['shopMoney' => ['amount' => '50', 'currencyCode' => 'USD']]];
        $second = [...$first, 'legacyResourceId' => '43', 'name' => '#2', 'createdAt' => '2026-09-04', 'cancelledAt' => null, 'email' => 'b@x.com'];
        Http::fake(['*' => Http::sequence()
            ->push(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'next'], 'edges' => [['node' => $first]]]]])
            ->push(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => $second]]]]])]);
        $client = new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);

        $result = $client->customerLtvCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-09-01', '2026-09-07');

        $this->assertSame(['#1', '#2'], array_column($result['orders'], 'name'));
        $this->assertSame('2026-09-03', $result['orders'][0]['cancelled_at']);
        $this->assertSame(2, $result['pages']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-07T23:59:59Z' && $request['variables']['after'] === 'next' && str_contains((string) $request['query'], 'cancelledAt'));
    }
}
