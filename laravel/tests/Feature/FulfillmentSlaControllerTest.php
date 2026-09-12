<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FulfillmentSlaControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_configuration_success_and_safe_failure(): void
    {
        $this->get('/reports/fulfillment-sla')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/fulfillment-sla')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/fulfillment-sla', [...$this->input(), 'threshold' => 0])->assertSessionHasErrors('threshold');
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/fulfillment-sla', $this->input())->assertOk()->assertSeeText('credentials are incomplete');

        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('fulfillmentSlaCandidates')->andReturn(['orders' => [['name' => '<script>', 'created_at' => now()->subDays(10)->toIso8601String(), 'financial_status' => 'paid']], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/fulfillment-sla', $this->input())->assertOk()->assertSeeText('1 orders scanned · 1 breaches')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false);

        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('fulfillmentSlaCandidates')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/fulfillment-sla', $this->input())->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('fulfillmentSlaCandidates')->andReturn(['orders' => [['name' => '=bad', 'created_at' => now()->subDays(10)->toIso8601String(), 'financial_status' => 'paid']], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        $response = $this->actingAs($operator)->post(route('reports.fulfillment-sla.export'), $this->input());
        $response->assertOk()->assertDownload('fulfillment-sla-2026-06-01-to-2026-06-30.csv');
        $this->assertStringContainsString("'=bad", $response->streamedContent());
    }

    private function input(): array
    {
        return ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'threshold' => 3];
    }

    /** @return array{User, Store} */
    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
