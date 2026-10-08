<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class AllViewsSmokeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authenticated_application_screens_return_successful_html(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);
        $shopify = Mockery::mock(ShopifyTransport::class);
        $shopify->shouldReceive('get')->zeroOrMoreTimes()->andReturn(['webhooks' => []]);
        $this->app->instance(ShopifyTransport::class, $shopify);
        Cache::put('health:checks:schedule:latestHeartbeatAt', now()->timestamp);

        $this->actingAs($admin);
        $screens = $this->applicationScreenRoutes();
        $this->assertNotEmpty($screens);
        foreach ($screens as $name) {
            $response = $this->get(route($name));
            $response->assertOk()->assertSee('<html lang="en">', false);
            $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'), $name);
        }
    }
}
