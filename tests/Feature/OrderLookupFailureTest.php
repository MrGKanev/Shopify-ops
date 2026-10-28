<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsOrderLookupFixtures;
use Tests\TestCase;

class OrderLookupFailureTest extends TestCase
{
    use BuildsOrderLookupFixtures;
    use LazilyRefreshDatabase;

    public function test_incomplete_shipstation_credentials_return_a_safe_error(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->makeUserAndStore([
            'shipstation_api_secret' => null,
        ]);
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->shopifyResponse()),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('The order lookup could not be completed.')
            ->assertDontSee('The ShipStation credentials are incomplete.');
        Http::assertSentCount(1);
    }

    public function test_upstream_failure_returns_a_safe_error_after_retrying_graphql(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->makeUserAndStore();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'message' => 'private upstream details',
            ], 500),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('The order lookup could not be completed.')
            ->assertDontSee('private upstream details');
        Http::assertSentCount(4);
    }
}
