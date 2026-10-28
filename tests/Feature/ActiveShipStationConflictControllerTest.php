<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Support\ShipStationFixtures;
use Tests\Support\ShopifyFixtures;
use Tests\TestCase;

class ActiveShipStationConflictControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_validation_configuration_success_and_safe_failure(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/active-shipstation-conflicts', ['start_date' => 'bad', 'end_date' => '2026-06-30'])->assertSessionHasErrors('start_date');
        [$operator] = $this->userWithStore(true, ['shipstation_api_key' => '']);
        $this->actingAs($operator)->post('/reports/active-shipstation-conflicts', $this->input())->assertOk()->assertSeeText('credentials are required');
        [$operator] = $this->userWithStore(true);
        $this->bindSuccess('<script>', true);
        $this->actingAs($operator)->post('/reports/active-shipstation-conflicts', $this->input())->assertOk()->assertSeeText('1 Shopify exceptions vs 1 active SS orders')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false);
        ShipStationFixtures::bindFailure(new RuntimeException('secret-token'));
        $this->actingAs($operator)->post('/reports/active-shipstation-conflicts', $this->input())->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->bindSuccess('=bad');
        $response = $this->actingAs($operator)->post(route('reports.active-shipstation-conflicts.export'), $this->input());
        $response->assertOk();
        $this->assertStringContainsString("'=bad", $response->streamedContent());
    }

    private function bindSuccess(string $email, bool $truncated = false): void
    {
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('fetchActiveOrders')->andReturn([['orderId' => 1, 'orderNumber' => '1001', 'orderStatus' => 'on_hold']]);
        $gateway = Mockery::mock(ShopifyOrders::class);
        $gateway->shouldReceive('itemMismatchCandidates')->andReturn(ShopifyFixtures::history([['id' => 1, 'name' => '#1001', 'created_at' => '2026-06-01', 'financial_status' => 'refunded', 'email' => $email]], $truncated, $truncated ? 100 : 1));
        $this->app->instance(ShopifyOrders::class, $gateway);
    }

    private function input(): array
    {
        return ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'];
    }

    /** @return array{User,Store} */
}
