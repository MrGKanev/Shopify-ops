<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Mockery;
use Tests\TestCase;

class AllViewsSmokeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_every_parameterless_application_screen_renders_without_server_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('get')->zeroOrMoreTimes()->andReturn(['webhooks' => []]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $failures = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (! $this->isScreen($route)) {
                continue;
            }
            $response = $this->actingAs($admin)->get('/'.$route->uri());
            if ($response->getStatusCode() >= 500) {
                $failures[] = $route->getName().': '.$response->getStatusCode();
            }
        }

        $this->assertSame([], $failures);
    }

    private function isScreen(Route $route): bool
    {
        $controller = $route->getActionName();

        return in_array('GET', $route->methods(), true)
            && ! str_contains($route->uri(), '{')
            && str_starts_with($controller, 'App\\Http\\Controllers\\')
            && ! in_array($route->getName(), ['login', 'auth.google.redirect', 'auth.google.callback'], true);
    }
}
