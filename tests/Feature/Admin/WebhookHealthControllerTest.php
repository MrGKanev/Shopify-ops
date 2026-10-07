<?php

namespace Tests\Feature\Admin;

use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WebhookHealthControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_sees_normalized_live_webhooks_and_unhealthy_endpoints(): void
    {
        [$admin, $store] = $this->makeUserAndStore(true);
        $gateway = Mockery::mock(ShopifyTransport::class);
        config(['app.url' => 'https://ops.example.test']);
        $gateway->shouldReceive('paginateGraphql')->once()->andReturn(['edges' => [
            ['node' => ['id' => 'gid://shopify/WebhookSubscription/1', 'topic' => 'ORDERS_CREATE', 'uri' => 'https://ops.example.test/webhooks/shopify/'.$store->slug, 'format' => 'JSON', 'createdAt' => '2026-09-01', 'apiVersion' => ['handle' => '2026-07']]],
            ['node' => ['id' => 'gid://shopify/WebhookSubscription/2', 'topic' => '<script>', 'uri' => 'http://unsafe.test', 'format' => 'JSON', 'apiVersion' => ['handle' => '2025-01']]],
        ], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyTransport::class, $gateway);

        $this->actingAs($admin)->get('/admin/webhook-health')->assertOk()->assertSeeText('orders/create')->assertSeeText('Healthy')->assertSeeText('Review')->assertDontSee('<script>', false);
    }

    public function test_page_is_admin_only_and_handles_missing_credentials(): void
    {
        [$operator] = $this->makeUserAndStore();
        $this->actingAs($operator)->get('/admin/webhook-health')->assertForbidden();
        [$admin] = $this->makeUserAndStore(true, ['shopify_access_token' => '']);
        $this->actingAs($admin)->get('/admin/webhook-health')->assertOk()->assertSeeText('Shopify credentials are incomplete');
    }

    public function test_an_unexpected_response_shape_is_safe(): void
    {
        [$admin] = $this->makeUserAndStore(true);
        $gateway = Mockery::mock(ShopifyTransport::class);
        $gateway->shouldReceive('paginateGraphql')->once()->andReturn(['edges' => [['node' => ['unexpected' => true]]], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyTransport::class, $gateway);

        $this->actingAs($admin)->get('/admin/webhook-health')->assertOk()->assertSeeText('could not be loaded');
    }

    public function test_transport_failures_are_safe(): void
    {
        [$admin] = $this->makeUserAndStore(true);
        $gateway = Mockery::mock(ShopifyTransport::class);
        $gateway->shouldReceive('paginateGraphql')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyTransport::class, $gateway);

        $this->actingAs($admin)->get('/admin/webhook-health')->assertOk()->assertSeeText('could not be loaded')->assertDontSeeText('secret-token');
    }

    /** @return array{User,Store} */
    private function makeUserAndStore(bool $admin = false, array $attributes = []): array
    {
        $user = $admin ? User::factory()->admin()->create() : User::factory()->operator()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
