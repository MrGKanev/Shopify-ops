<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class MetafieldControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_configuration_validation_and_search_output(): void
    {
        $this->get('/metafields')->assertRedirect(route('login'));
        [$user] = $this->userWithStore(['shopify_access_token' => '']);
        $this->actingAs($user)->get('/metafields')->assertOk()->assertSeeText('credentials are incomplete');
        $this->actingAs($user)->post('/metafields/search', [])->assertSessionHasErrors(['namespace', 'key']);
        $this->actingAs($user)->post('/metafields/lookup', ['orders' => implode(',', range(1, 21))])->assertSessionHasErrors('orders');

        [$user] = $this->userWithStore();
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('orderMetafieldDefinitions')->andReturn([['namespace' => 'custom', 'key' => '<script>']]);
        $gateway->shouldReceive('searchOrdersByMetafield')->andReturn(['orders' => [], 'scanned' => 2, 'with_metafield' => 1, 'sample_values' => [], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($user)->post('/metafields/search', ['namespace' => 'custom', 'key' => 'gift'])->assertOk()->assertSeeText('2 scanned · 1 with metafield · 0 matches')->assertDontSee('<script>', false);
    }

    private function userWithStore(array $attributes = []): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
