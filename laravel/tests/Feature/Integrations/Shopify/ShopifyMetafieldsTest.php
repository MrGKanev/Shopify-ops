<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyMetafieldsTest extends TestCase
{
    public function test_definitions_and_value_search_are_normalized_and_counted(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => str_contains((string) $request['query'], 'OrderMetafieldDefinitions')
            ? Http::response(['data' => ['metafieldDefinitions' => ['edges' => [['node' => ['namespace' => 'custom', 'key' => 'gift']]]]]])
            : Http::response(['data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [['node' => $this->order('Happy Birthday')], ['node' => $this->order(null)]],
            ]]]));
        $client = $this->client();
        $store = $this->store();

        $this->assertSame('gift', $client->orderMetafieldDefinitions($store)[0]['key']);
        $result = $client->searchOrdersByMetafield($store, 'custom', 'gift', 'birthday', null, null);
        $this->assertSame([2, 1, 1, ['Happy Birthday']], [$result['scanned'], $result['with_metafield'], count($result['orders']), $result['sample_values']]);
    }

    public function test_order_metafields_use_gid_and_normalize_values(): void
    {
        Http::fake(['*' => Http::response(['data' => ['order' => ['metafields' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => [['id' => 'gid://shopify/Metafield/1', 'namespace' => 'custom', 'key' => 'gift', 'value' => '{"x":1}', 'type' => 'json']]]]]])]);
        $result = $this->client()->orderMetafields($this->store(), [42]);
        $this->assertSame('{"x":1}', $result['42'][0]['value']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['id'] === 'gid://shopify/Order/42');
    }

    private function order(?string $value): array
    {
        return ['legacyResourceId' => '42', 'name' => '#1', 'createdAt' => '2026-01-01', 'email' => 'a@x.com', 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED', 'totalPriceSet' => ['shopMoney' => ['amount' => '10', 'currencyCode' => 'USD']], 'metafield' => $value === null ? null : ['value' => $value, 'type' => 'text']];
    }

    private function client(): ShopifyAdminClient
    {
        return new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);
    }

    private function store(): Store
    {
        return new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']);
    }
}
