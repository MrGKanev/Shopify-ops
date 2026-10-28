<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsOrderLookupFixtures;
use Tests\TestCase;

class OrderLookupComparisonTest extends TestCase
{
    use BuildsOrderLookupFixtures;
    use LazilyRefreshDatabase;

    public function test_matching_order_details_render_a_successful_cross_platform_comparison(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->makeUserAndStore();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->comparisonShopifyResponse()),
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [$this->matchingShipStationOrder()],
                'pages' => 1,
            ]),
            'https://ssapi.shipstation.com/shipments*' => Http::response([
                'shipments' => [],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('Detailed comparison')
            ->assertSeeText('SKU quantities match.')
            ->assertSeeText('Shipping address line 1')
            ->assertDontSee('ShipStation is shipped while Shopify is not fully fulfilled.');
        Http::assertSentCount(3);
    }

    public function test_item_and_established_status_differences_are_highlighted(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->makeUserAndStore();
        $shopifyResponse = $this->comparisonShopifyResponse();
        $shopifyResponse['data']['orders']['edges'][0]['node']['displayFulfillmentStatus'] = 'UNFULFILLED';
        $shipStationOrder = $this->matchingShipStationOrder();
        $shipStationOrder['items'] = [
            ['sku' => 'widget', 'quantity' => 1, 'name' => 'Widget'],
            ['sku' => 'unexpected', 'quantity' => 2, 'name' => 'Unexpected'],
        ];
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($shopifyResponse),
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [$shipStationOrder],
                'pages' => 1,
            ]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['shipments' => []]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('ShipStation is shipped while Shopify is not fully fulfilled.')
            ->assertSeeText('Missing from ShipStation')
            ->assertSeeText('Extra in ShipStation')
            ->assertSeeText('cable')
            ->assertSeeText('unexpected');
        Http::assertSentCount(3);
    }

    public function test_multiple_shipstation_matches_are_reported_without_selecting_one(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->makeUserAndStore();
        $firstOrder = $this->matchingShipStationOrder();
        $secondOrder = [...$firstOrder, 'orderId' => 43];
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->comparisonShopifyResponse()),
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [$firstOrder, $secondOrder],
                'pages' => 1,
            ]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['shipments' => []]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('Multiple matching records were found. No record was selected automatically.')
            ->assertDontSee('SKU quantities match.');
        Http::assertSentCount(3);
    }

    public function test_empty_results_are_shown_without_being_treated_as_an_error(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->makeUserAndStore();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => ['edges' => []]],
            ]),
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [],
                'pages' => 1,
            ]),
            'https://ssapi.shipstation.com/shipments*' => Http::response([
                'shipments' => [],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('No Shopify order found.')
            ->assertSeeText('No ShipStation order found.')
            ->assertSeeText('Comparison unavailable because the order was not found in Shopify.')
            ->assertDontSee('The order lookup could not be completed.');
        Http::assertSentCount(2);
    }

    public function test_missing_shipstation_order_is_a_valid_comparison_state(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->makeUserAndStore();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->comparisonShopifyResponse()),
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [],
                'pages' => 1,
            ]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['shipments' => []]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('Comparison unavailable because the order was not found in ShipStation.')
            ->assertDontSee('The order lookup could not be completed.');
        Http::assertSentCount(2);
    }
}
