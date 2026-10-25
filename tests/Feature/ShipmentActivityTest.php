<?php

namespace Tests\Feature;

use App\Application\Reports\RunShipmentActivityReport;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Models\ShipmentMovementObservation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use UnexpectedValueException;

class ShipmentActivityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_counts_created_orders_and_only_explicit_recorded_acceptance_in_store_local_days(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $store = Store::factory()->create(['shopify_timezone' => 'Europe/Sofia']);
        $this->orders([
            ['id' => 'gid://shopify/Order/1', 'createdAt' => '2026-10-23T21:30:00Z', 'test' => false],
            ['id' => 'gid://shopify/Order/2', 'createdAt' => '2026-10-23T12:00:00Z', 'test' => false, 'cancelledAt' => '2026-10-24T12:00:00Z'],
            ['id' => 'gid://shopify/Order/3', 'createdAt' => '2026-10-23T12:00:00Z', 'test' => true],
        ]);
        ShipmentMovementObservation::factory()->forAccount($store)->create(['shipment_id' => '1', 'carrier_acceptance_at' => '2026-10-23 21:15:00']);
        ShipmentMovementObservation::factory()->forAccount($store)->create(['shipment_id' => '1', 'carrier_acceptance_at' => '2026-10-24 10:00:00']);
        ShipmentMovementObservation::factory()->forAccount($store)->create(['shipment_id' => '2', 'last_progress_at' => '2026-10-24 10:00:00']);
        ShipmentMovementObservation::factory()->forAccount($store)->create(['shipment_id' => '3', 'carrier_acceptance_at' => '2026-10-22 10:00:00']);
        ShipmentMovementObservation::factory()->forAccount($store)->create(['shipment_id' => '3', 'carrier_acceptance_at' => '2026-10-24 10:00:00']);
        ShipmentMovementObservation::factory()->create(['carrier_acceptance_at' => '2026-10-24 10:00:00']);
        ShipmentMovementObservation::factory()->for($store)->create(['account_fingerprint' => 'old-account', 'carrier_acceptance_at' => '2026-10-24 10:00:00']);

        $result = app(RunShipmentActivityReport::class)->handle($store, '2026-10-23', '2026-10-24');

        $this->assertSame([
            ['date' => '2026-10-23', 'created_orders' => 1, 'recorded_carrier_acceptances' => 0],
            ['date' => '2026-10-24', 'created_orders' => 1, 'recorded_carrier_acceptances' => 1],
        ], $result->rows);
        $this->assertSame('observed_labels_only', $result->meta['carrier_coverage']);
    }

    public function test_absent_tracking_coverage_is_unknown_while_confirmed_empty_shopify_counts_are_zero(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $store = Store::factory()->create();
        $this->orders([]);

        $result = app(RunShipmentActivityReport::class)->handle($store, '2026-10-23', '2026-10-24');

        $this->assertSame([0, 0], array_column($result->rows, 'created_orders'));
        $this->assertSame([null, null], array_column($result->rows, 'recorded_carrier_acceptances'));
        $this->assertNull($result->meta['tracking_observed_as_of']);
    }

    public function test_truncated_arrival_reads_never_become_daily_zero_rows(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $store = Store::factory()->create();
        $this->mock(ShopifyOrders::class)->shouldReceive('shipmentArrivalCandidates')->once()->andReturn(['orders' => [], 'pages' => 100, 'truncated' => true, 'scopes' => ['read_orders']]);
        $this->expectException(UnexpectedValueException::class);
        app(RunShipmentActivityReport::class)->handle($store, '2026-10-23', '2026-10-24');
    }

    public function test_duplicate_order_ids_do_not_inflate_arrival_counts(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $store = Store::factory()->create();
        $order = ['id' => 'gid://shopify/Order/1', 'createdAt' => '2026-10-23T12:00:00Z', 'test' => false];
        $this->orders([$order, $order]);
        $this->expectException(UnexpectedValueException::class);
        app(RunShipmentActivityReport::class)->handle($store, '2026-10-23', '2026-10-24');
    }

    public function test_controller_rejects_open_days_and_exports_the_saved_source_coverage_without_refetching(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        [$operator, $store] = $this->userWithStore(true);
        $this->orders([]);
        $this->actingAs($operator)->post('/reports/shipment-activity', ['start_date' => '2026-10-24', 'end_date' => '2026-10-25'])->assertSessionHasErrors('end_date');
        $dates = ['start_date' => '2026-10-23', 'end_date' => '2026-10-24'];
        $this->post('/reports/shipment-activity', $dates)->assertSeeText('Order arrivals and carrier acceptance');
        $response = $this->post(route('reports.shipment-activity.export'), $dates);
        $response->assertDownload('shipment-activity-2026-10-23-to-2026-10-24.csv');
        $this->assertStringContainsString('observed_labels_only', $response->streamedContent());
        $this->assertStringContainsString('read_orders', $response->streamedContent());
        $run = $store->reportRuns()->sole();
        $this->travel(2)->days();
        $store->update(['shopify_access_token' => '']);
        $this->get(route('reports.shipment-activity.result', [...$dates, 'report_run' => $run->id]))->assertSeeText('Order arrivals and carrier acceptance');
        $saved = $this->post(route('reports.shipment-activity.export'), [...$dates, 'report_run' => $run->id]);
        $this->assertSame($response->streamedContent(), $saved->streamedContent());
    }

    public function test_gateway_uses_shop_local_creation_bounds_and_does_not_filter_later_cancellations(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $store = Store::factory()->create(['shopify_timezone' => 'Europe/Sofia']);
        Http::preventStrayRequests();
        Http::fake(['*.myshopify.com/admin/api/2026-07/graphql.json' => function (Request $request) {
            if (str_contains($request['query'], 'query ShopifyBacklogAccess')) {
                return Http::response(['data' => ['currentAppInstallation' => ['accessScopes' => [['handle' => 'read_orders']]]]]);
            }
            $this->assertSame('status:any created_at:>=2026-10-22T21:00:00Z created_at:<=2026-10-24T20:59:59Z', $request['variables']['search']);

            return Http::response(['data' => ['orders' => ['edges' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
        }]);

        $result = app(ShopifyAdminClient::class)->shipmentArrivalCandidates($store, '2026-10-23', '2026-10-24');

        $this->assertSame([], $result['orders']);
        $this->assertSame(['read_orders'], $result['scopes']);
        Http::assertSentCount(2);
    }

    public function test_old_periods_without_full_history_access_fail_before_a_false_empty_query(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $store = Store::factory()->create();
        Http::preventStrayRequests();
        Http::fake(['*.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['currentAppInstallation' => ['accessScopes' => [['handle' => 'read_orders']]]]])]);
        try {
            $this->expectException(ShopifyGraphqlException::class);
            app(ShopifyAdminClient::class)->shipmentArrivalCandidates($store, '2026-01-01', '2026-01-31');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_scheduling_keeps_completed_days_and_defaults_to_paused(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);
        $this->orders([]);
        $this->actingAs($admin)->post('/reports/shipment-activity', ['start_date' => '2026-10-23', 'end_date' => '2026-10-24'])->assertSeeText('Order arrivals and carrier acceptance');
        $run = $store->reportRuns()->sole();
        $input = ['source_run_id' => $run->id, 'name' => 'Activity history', 'enabled' => false, 'frequency' => 'daily', 'time' => '07:00', 'timezone' => 'UTC', 'weekday' => 1, 'month_day' => 1, 'lookback_days' => 2, 'end_offset_days' => 0, 'notification_enabled' => false, 'notification_threshold' => 1];
        $this->post(route('admin.report-schedules.store'), $input)->assertSessionHasErrors('end_offset_days');
        $this->assertSame(0, $store->reportSchedules()->count());
        $this->post(route('admin.report-schedules.store'), [...$input, 'end_offset_days' => 1])->assertRedirect();
        $schedule = $store->reportSchedules()->sole();
        $this->assertSame('shipment_activity', $schedule->tool);
        $this->assertTrue($schedule->uses_date_range);
        $this->assertFalse($schedule->enabled);
    }

    private function orders(array $orders): void
    {
        $this->mock(ShopifyOrders::class)->shouldReceive('shipmentArrivalCandidates')->once()->andReturn(['orders' => $orders, 'scopes' => ['read_orders'], 'pages' => 1, 'truncated' => false]);
    }
}
