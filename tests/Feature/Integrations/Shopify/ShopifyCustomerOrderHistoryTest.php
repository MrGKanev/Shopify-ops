<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyCustomerOrderHistoryTest extends TestCase
{
    public function test_it_normalizes_orders_and_uses_first_available_customer(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '42', 'name' => '#1', 'createdAt' => '2026-09-02', 'cancelledAt' => null, 'email' => 'jane@example.com', 'tags' => ['VIP'], 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED', 'totalPriceSet' => ['shopMoney' => ['amount' => '50', 'currencyCode' => 'USD']], 'customer' => ['firstName' => 'Jane']]]]]]])]);
        $client = new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);

        $result = $client->customerOrderHistory(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), ' Jane@Example.com ');

        $this->assertSame('Jane', $result['customer']['firstName']);
        $this->assertSame('paid', $result['orders'][0]['financial_status']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'email:"jane@example.com"' && str_contains((string) $request['query'], 'customer'));
    }
}
