<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class OnHoldStallControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_report_results_include_truncation_and_escape_remote_text(): void
    {
        $this->travelTo('2026-07-01 12:00:00');
        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyOrders::class);
        $gateway->shouldReceive('onHoldFulfillmentCandidates')->once()->andReturn($this->candidates('<script>', true));
        $this->app->instance(ShopifyOrders::class, $gateway);

        $this->actingAs($operator)->post('/reports/on-hold-stall', $this->input())
            ->assertSeeText('1 on-hold fulfillment orders')
            ->assertSeeText('truncated after 100 pages')
            ->assertDontSee('<script>', false);
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyOrders::class);
        $gateway->shouldReceive('onHoldFulfillmentCandidates')->andReturn($this->candidates('=bad'));
        $this->app->instance(ShopifyOrders::class, $gateway);

        $response = $this->actingAs($operator)->post(route('reports.on-hold-stall.export'), $this->input());
        $response->assertOk()->assertDownload('on-hold-stalls-2026-06-01-to-2026-06-30.csv');
        $this->assertStringContainsString("'=bad", $response->streamedContent());
    }

    private function candidates(string $name, bool $truncated = false): array
    {
        return ['fulfillment_orders' => [['order' => ['legacyResourceId' => '1', 'name' => $name, 'createdAt' => now()->subDays(10)->toIso8601String(), 'displayFinancialStatus' => 'PAID'], 'fulfillmentHolds' => [['reason' => 'MANUAL']]]], 'pages' => $truncated ? 100 : 1, 'truncated' => $truncated];
    }

    private function input(): array
    {
        return ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'];
    }

    /** @return array{User, Store} */
}
