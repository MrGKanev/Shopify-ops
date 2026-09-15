<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NoTrackingControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_configuration_success_and_safe_failure(): void
    {
        $this->get('/reports/no-tracking')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/no-tracking')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/no-tracking', [...$this->input(), 'threshold' => 0])->assertSessionHasErrors('threshold');
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/no-tracking', $this->input())->assertOk()->assertSeeText('credentials are incomplete');
        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('noTrackingCandidates')->andReturn($this->candidates('<script>', true));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/no-tracking', $this->input())->assertOk()->assertSeeText('1 fulfilled orders scanned')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('noTrackingCandidates')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/no-tracking', $this->input())->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('noTrackingCandidates')->andReturn($this->candidates('=bad'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $response = $this->actingAs($operator)->post(route('reports.no-tracking.export'), $this->input());
        $response->assertOk()->assertDownload('fulfilled-without-tracking-2026-06-01-to-2026-06-30.csv');
        $this->assertStringContainsString("'=bad", $response->streamedContent());
    }

    private function candidates(string $name, bool $truncated = false): array
    {
        return ['orders' => [['name' => $name, 'created_at' => '2025-01-01', 'fulfillments' => [['created_at' => '2026-06-15T12:00:00Z', 'tracking_number' => '']]]], 'pages' => $truncated ? 100 : 1, 'truncated' => $truncated];
    }

    private function input(): array
    {
        return ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'threshold' => 24];
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
