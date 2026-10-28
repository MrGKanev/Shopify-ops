<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MetafieldControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_configuration_validation_and_search_output(): void
    {
        $this->get('/metafields')->assertRedirect(route('login'));
        [$user] = $this->makeUserAndStore(['shopify_access_token' => '']);
        $this->actingAs($user)->get('/metafields')->assertOk()->assertSeeText('credentials are incomplete');
        $this->actingAs($user)->post('/metafields/search', [])->assertSessionHasErrors(['namespace', 'key']);
        $this->actingAs($user)->post('/metafields/lookup', ['orders' => implode(',', range(1, 21))])->assertSessionHasErrors('orders');

        [$user] = $this->makeUserAndStore();
        $gateway = Mockery::mock(ShopifyOrders::class);
        $gateway->shouldReceive('orderMetafieldDefinitions')->andReturn([['namespace' => 'custom', 'key' => '<script>']]);
        $gateway->shouldReceive('searchOrdersByMetafield')->andReturn(['orders' => [], 'scanned' => 2, 'with_metafield' => 1, 'sample_values' => [], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyOrders::class, $gateway);
        $this->actingAs($user)->post('/metafields/search', ['namespace' => 'custom', 'key' => 'gift'])->assertOk()->assertSeeText('2 scanned · 1 with metafield · 0 matches')->assertDontSee('<script>', false);
    }

    public function test_definition_and_search_failures_are_reported_without_leaking_the_error(): void
    {
        [$user] = $this->makeUserAndStore();
        $gateway = Mockery::mock(ShopifyOrders::class);
        $gateway->shouldReceive('orderMetafieldDefinitions')->andThrow(new RuntimeException('secret-token'));
        $gateway->shouldReceive('searchOrdersByMetafield')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyOrders::class, $gateway);

        $this->actingAs($user)->post('/metafields/search', ['namespace' => 'custom', 'key' => 'gift'])
            ->assertOk()->assertSeeText('definitions could not be loaded')->assertSeeText('operation could not be completed')->assertDontSeeText('secret-token');
    }

    private function makeUserAndStore(array $attributes = []): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
