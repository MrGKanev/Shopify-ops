<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DuplicateOrderControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_defaults_validation_and_configuration_guard(): void
    {
        $this->get('/reports/duplicate-orders')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/duplicate-orders')->assertForbidden();
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->travelTo('2026-09-07');
        $this->actingAs($operator)->get('/reports/duplicate-orders')->assertOk()->assertSee('2026-08-08')->assertSee('2026-09-07');
        $this->actingAs($operator)->post('/reports/duplicate-orders', ['start_date' => 'bad', 'end_date' => '2026-09-01'])->assertSessionHasErrors('start_date');
        $this->actingAs($operator)->post('/reports/duplicate-orders', ['start_date' => '2026-09-02', 'end_date' => '2026-09-01'])->assertSessionHasErrors('end_date');
        $this->actingAs($operator)->post('/reports/duplicate-orders', ['start_date' => '2026-09-01', 'end_date' => '2026-09-01'])->assertOk()->assertSeeText('credentials are incomplete');
    }

    public function test_success_truncation_xss_and_safe_failure(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $orders = [
            ['id' => '42', 'name' => '#<script>', 'email' => '<img>@x.com', 'total_price' => '10.00', 'currency' => 'USD', 'created_at' => '2026-09-01T10:00:00Z', 'financial_status' => 'paid'],
            ['id' => '43', 'name' => '#2', 'email' => '<img>@x.com', 'total_price' => '10.00', 'currency' => 'USD', 'created_at' => '2026-09-01T10:05:00Z', 'financial_status' => 'paid'],
        ];
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('duplicateOrderCandidates')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-09-01', '2026-09-07')->andReturn(['orders' => $orders, 'pages' => 40, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/duplicate-orders', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('2 scanned · 1 duplicate pairs')->assertSeeText('truncated after 40 pages')->assertDontSee('<script>', false)->assertDontSee('<img>', false);

        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('duplicateOrderCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/duplicate-orders', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
