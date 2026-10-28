<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Support\ShipStationFixtures;
use Tests\Support\ShopifyFixtures;
use Tests\TestCase;

class RefundTrackerControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_validation_and_configuration_states(): void
    {

        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/refund-tracker', ['start_date' => 'bad', 'end_date' => '2026-01-01'])->assertSessionHasErrors('start_date');

        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/refund-tracker', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('Shopify credentials are incomplete');

        [$operator] = $this->userWithStore(true, ['shipstation_api_secret' => '']);
        $this->actingAs($operator)->post('/reports/refund-tracker', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('ShipStation credentials are incomplete');
    }

    public function test_it_cross_checks_refunds_and_escapes_output(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyPayments::class);
        $gateway->shouldReceive('refundTrackerCandidates')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-01-01', '2026-01-31')->andReturn([
            'orders' => [['id' => '1', 'name' => '#<script>', 'order_number' => '1001', 'email' => '<img>@example.com', 'financial_status' => 'refunded', 'total_price' => 20, 'refunds' => []]],
            'pages' => 100,
            'truncated' => true,
        ]);
        $client = ShipStationFixtures::bindClient(1, $store);
        $client->shouldReceive('fetchAllOrders')->once()->with('2026-01-01', '2026-02-07')->andReturn([ShipStationFixtures::lookupOrder(1, '1001', (int) $store->store_number, ['orderStatus' => 'awaiting_shipment'])]);
        $this->app->instance(ShopifyPayments::class, $gateway);

        $this->actingAs($operator)->post('/reports/refund-tracker', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])
            ->assertOk()
            ->assertSeeText('Still active in ShipStation')
            ->assertSeeText('truncated after 100 pages')
            ->assertSee('form="bulk-ignore"', false)
            ->assertSee('name="order_numbers[]" value="#&lt;script&gt;"', false)
            ->assertDontSee('<script>', false)
            ->assertDontSee('<img>', false);
    }

    public function test_it_runs_without_shipstation_and_hides_failures(): void
    {
        [$operator] = $this->userWithStore(true, ['shipstation_api_key' => '', 'shipstation_api_secret' => '']);
        $gateway = Mockery::mock(ShopifyPayments::class);
        $gateway->shouldReceive('refundTrackerCandidates')->once()->andReturn(ShopifyFixtures::history([]));
        $this->app->instance(ShopifyPayments::class, $gateway);
        $this->actingAs($operator)->post('/reports/refund-tracker', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('shown without a cross-check');

        $gateway = Mockery::mock(ShopifyPayments::class);
        $gateway->shouldReceive('refundTrackerCandidates')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyPayments::class, $gateway);
        $this->actingAs($operator)->post('/reports/refund-tracker', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    /** @return array{User, Store} */
}
