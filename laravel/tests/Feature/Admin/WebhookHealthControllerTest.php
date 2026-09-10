<?php

namespace Tests\Feature\Admin;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
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
        [$admin, $store] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('get')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), 'webhooks.json', ['limit' => 250])->andReturn(['webhooks' => [['id' => 1, 'topic' => 'orders/create', 'address' => 'https://example.test/hook', 'format' => 'json', 'created_at' => '2026-09-01T10:00:00Z', 'api_version' => '2026-07'], ['id' => 2, 'topic' => '<script>', 'address' => 'http://unsafe.test', 'api_version' => '2025-01']]]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        $this->actingAs($admin)->get('/admin/webhook-health')->assertOk()->assertSeeText('orders/create')->assertSeeText('Healthy')->assertSeeText('Review')->assertDontSee('<script>', false);
    }

    public function test_page_is_admin_only_and_handles_missing_credentials(): void
    {
        [$operator] = $this->userWithStore();
        $this->actingAs($operator)->get('/admin/webhook-health')->assertForbidden();
        [$admin] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($admin)->get('/admin/webhook-health')->assertOk()->assertSeeText('Shopify credentials are incomplete');
    }

    public function test_an_unexpected_response_shape_is_safe(): void
    {
        [$admin] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('get')->once()->andReturn(['unexpected' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        $this->actingAs($admin)->get('/admin/webhook-health')->assertOk()->assertSeeText('unexpected webhook response');
    }

    public function test_transport_failures_are_safe(): void
    {
        [$admin] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('get')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        $this->actingAs($admin)->get('/admin/webhook-health')->assertOk()->assertSeeText('could not be loaded')->assertDontSeeText('secret-token');
    }

    /** @return array{User,Store} */
    private function userWithStore(bool $admin = false, array $attributes = []): array
    {
        $user = $admin ? User::factory()->admin()->create() : User::factory()->operator()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
