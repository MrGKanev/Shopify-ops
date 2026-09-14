<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CustomerLtvControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_configuration_success_and_safe_failure(): void
    {
        $this->get('/reports/customer-ltv')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/customer-ltv')->assertForbidden();
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->travelTo('2026-09-07');
        $this->actingAs($operator)->get('/reports/customer-ltv')->assertOk()->assertSee('2025-09-07')->assertSee('2026-09-07');
        $this->actingAs($operator)->post('/reports/customer-ltv', ['start_date' => 'bad', 'end_date' => '2026-09-01'])->assertSessionHasErrors('start_date');
        $this->actingAs($operator)->post('/reports/customer-ltv', ['start_date' => '2026-09-02', 'end_date' => '2026-09-01'])->assertSessionHasErrors('end_date');
        $this->actingAs($operator)->post('/reports/customer-ltv', ['start_date' => '2026-09-01', 'end_date' => '2026-09-01'])->assertOk()->assertSeeText('credentials are incomplete');

        [$operator, $store] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('customerLtvCandidates')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-09-01', '2026-09-07')->andReturn(['orders' => [['email' => '<script>@x.com', 'total_price' => 10, 'created_at' => '2026-09-01', 'cancelled_at' => null]], 'pages' => 1000, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/customer-ltv', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('1 orders · 1 customers · $10.00')->assertSeeText('truncated after 1000 pages')->assertDontSee('<script>', false);

        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('customerLtvCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/customer-ltv', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
