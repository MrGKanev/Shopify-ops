<?php

namespace Tests\Feature;

use App\Application\Reports\RunShipmentMovementMonitorReport;
use App\Application\Reports\TrackingReadBudget;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\TrackingReadLimitExceeded;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationTrackingClient;
use App\Models\ShipmentMovementObservation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\Support\ShipStationFixtures;
use Tests\TestCase;

class ShipmentMovementMonitorTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_rotates_unchecked_labels_first_and_enforces_the_shared_daily_cap(): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $store = $this->store(['daily_limit' => 3, 'max_labels' => 1]);
        $this->cohort([1, 2, 3]);
        $this->tracking();
        $monitor = app(RunShipmentMovementMonitorReport::class);

        $first = $monitor->handle($store);
        $second = $monitor->handle($store);
        $third = $monitor->handle($store);

        $this->assertSame(['1', '2', '3'], [$first->meta['observations'][0]['shipment_id'], $second->meta['observations'][0]['shipment_id'], $third->meta['observations'][0]['shipment_id']]);
        $this->assertTrue($first->truncated);
        $this->assertSame([], $first->rows);
        $this->assertSame(2, $first->meta['not_checked']);
        $this->assertSame(0, app(TrackingReadBudget::class)->remaining($store));
        $this->assertSame(3, $store->shipmentMovementObservations()->count());
        $this->assertSame('2026-10-23 10:00:00', $store->shipmentMovementObservations()->firstOrFail()->carrier_acceptance_at->format('Y-m-d H:i:s'));
        Http::assertSentCount(3);
        $this->expectException(TrackingReadLimitExceeded::class);
        $monitor->handle($store);
    }

    public function test_manual_and_automatic_reads_use_one_counter_and_failed_requests_are_not_refunded(): void
    {
        $this->travelTo('2026-10-24 23:30:00 UTC');
        $store = $this->store(['daily_limit' => 1]);
        Http::preventStrayRequests();
        Http::fake(['api.shipstation.com/v2/tracking*' => Http::response([], 503)]);
        try {
            app(ShipStationTrackingClient::class)->track($store, 'ups', 'TRACK-1');
            $this->fail('The upstream error must be propagated.');
        } catch (IntegrationException) {
            $this->assertSame(0, app(TrackingReadBudget::class)->remaining($store));
        }
        $store->update(['shopify_timezone' => 'Pacific/Auckland']);
        $this->assertSame(0, app(TrackingReadBudget::class)->remaining($store));
        try {
            $this->expectException(TrackingReadLimitExceeded::class);
            app(RunShipmentMovementMonitorReport::class)->handle($store);
        } finally {
            Http::assertSentCount(1);
            $this->travelTo('2026-10-25 00:00:00 UTC');
            $this->assertSame(1, app(TrackingReadBudget::class)->remaining($store));
            $this->assertDatabaseCount('shipment_movement_observations', 0);
        }
    }

    public function test_unverified_access_never_polls_and_key_rotation_revokes_verification(): void
    {
        $store = $this->store();
        $store->update(['shipstation_v2_api_key' => 'rotated']);
        $this->assertFalse($store->movementPolicy()['access_verified']);
        Http::preventStrayRequests();
        try {
            $this->expectException(LogicException::class);
            app(RunShipmentMovementMonitorReport::class)->handle($store);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('shipment_movement_observations', 0);
        }
    }

    public function test_foreign_parent_order_blocks_the_entire_cohort_before_paid_reads(): void
    {
        $store = $this->store();
        $this->cohort([1], 99);
        Http::preventStrayRequests();
        try {
            $this->expectException(UnexpectedResponse::class);
            app(RunShipmentMovementMonitorReport::class)->handle($store);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('shipment_movement_observations', 0);
            $this->assertDatabaseCount('operational_issues', 0);
        }
    }

    public function test_delivered_labels_and_unselected_carriers_do_not_consume_budget(): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $store = $this->store(['carriers' => ['ups']]);
        $shipment = $this->shipment(1);
        ShipmentMovementObservation::factory()->forAccount($store)->create(['identity' => ShipmentMovementObservation::shipmentIdentity($store, $shipment), 'shipment_id' => '1', 'classification' => 'delivered']);
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('recentMonitoringShipments')->once()->andReturn([$shipment, [...$this->shipment(2), 'carrierCode' => 'fedex']]);
        $client->shouldNotReceive('getOrder');
        Http::preventStrayRequests();

        $result = app(RunShipmentMovementMonitorReport::class)->handle($store);

        $this->assertSame([], $result->rows);
        $this->assertSame(2, $result->meta['skipped_labels']);
        $this->assertSame(100, $result->meta['budget_remaining']);
        Http::assertNothingSent();
    }

    public function test_a_paused_monitoring_schedule_can_run_and_its_result_never_repolls(): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $admin = User::factory()->admin()->create();
        $store = $this->store();
        $admin->stores()->attach($store);
        $this->actingAs($admin)->post(route('admin.report-schedules.store'), ['name' => 'Bounded monitoring', 'tool' => 'shipment_movement_monitor', 'enabled' => false, 'frequency' => 'daily', 'time' => '07:00', 'timezone' => 'UTC', 'weekday' => 1, 'month_day' => 1, 'lookback_days' => 30, 'end_offset_days' => 1, 'notification_enabled' => false, 'notification_threshold' => 1])->assertRedirect();
        $schedule = $store->reportSchedules()->sole();
        $this->assertFalse($schedule->enabled);
        $this->cohort([1]);
        $this->tracking();
        $this->post(route('admin.report-schedules.run', $schedule))->assertRedirect();
        $run = $schedule->runs()->sole();
        $this->assertSame('completed', $run->status);
        $this->assertFalse($schedule->fresh()->enabled);
        $this->get(route('reports.shipment-movement-monitor.result', ['report_run' => $run->id]))->assertSeeText('TRACK-1');
        $this->get(route('reports.shipment-movement-monitor.result', ['report_run' => $run->id]))->assertSeeText('Labels checked');
        Http::assertSentCount(1);
    }

    public function test_only_explicit_acceptance_events_are_recorded_and_unknown_reads_preserve_previous_evidence(): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $store = $this->store();
        $this->cohort([1]);
        $status = 'IT';
        $code = 'IT';
        Http::preventStrayRequests();
        Http::fake(['api.shipstation.com/v2/tracking*' => function (Request $request) use (&$status, &$code) {
            return Http::response(['tracking_number' => $request['tracking_number'], 'carrier_code' => 'ups', 'status_code' => $status, 'events' => $status === 'UN' ? [] : [['status_code' => $code, 'occurred_at' => '2026-10-23T10:00:00Z']]]);
        }]);
        app(RunShipmentMovementMonitorReport::class)->handle($store);
        $observation = $store->shipmentMovementObservations()->sole();
        $this->assertNull($observation->carrier_acceptance_at);
        $code = 'AC';
        app(RunShipmentMovementMonitorReport::class)->handle($store);
        $this->assertSame('2026-10-23 10:00:00', $observation->fresh()->carrier_acceptance_at->format('Y-m-d H:i:s'));
        $status = 'UN';
        app(RunShipmentMovementMonitorReport::class)->handle($store);
        $this->assertSame('movement_unknown', $observation->fresh()->classification);
        $this->assertSame('2026-10-23 10:00:00', $observation->fresh()->carrier_acceptance_at->format('Y-m-d H:i:s'));
        Http::assertSentCount(3);
    }

    public function test_lowering_the_limit_to_zero_stops_a_running_cohort_before_its_next_read(): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $store = $this->store(['max_labels' => 2]);
        $this->cohort([1, 2]);
        Http::preventStrayRequests();
        Http::fake(['api.shipstation.com/v2/tracking*' => function (Request $request) use ($store) {
            $current = $store->fresh();
            $current->update(['movement_policy' => [...$current->movementPolicy(), 'daily_limit' => 0]]);

            return Http::response(['tracking_number' => $request['tracking_number'], 'carrier_code' => 'ups', 'status_code' => 'EX', 'events' => []]);
        }]);
        try {
            $this->expectException(TrackingReadLimitExceeded::class);
            app(RunShipmentMovementMonitorReport::class)->handle($store);
        } finally {
            Http::assertSentCount(1);
            $this->assertDatabaseCount('shipment_movement_observations', 0);
            $this->assertDatabaseCount('operational_issues', 0);
        }
    }

    private function store(array $policy = []): Store
    {
        return Store::factory()->create(['store_number' => '42', 'shipstation_v2_api_key' => 'tracking-key', 'movement_policy' => [...(new Store)->movementPolicy(), 'access_verified' => true, ...$policy]]);
    }

    private function shipment(int $id): array
    {
        return ['shipmentId' => $id, 'orderId' => $id, 'orderNumber' => (string) (1000 + $id), 'carrierCode' => 'ups', 'trackingNumber' => 'TRACK-'.$id, 'createDate' => '2026-10-10T12:00:00Z'];
    }

    private function cohort(array $ids, int $storeId = 42): void
    {
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('recentMonitoringShipments')->andReturn(array_map(fn (int $id): array => $this->shipment($id), $ids));
        $client->shouldReceive('getOrder')->andReturnUsing(fn (int $id): array => ShipStationFixtures::lookupOrder($id, (string) (1000 + $id), $storeId));
    }

    private function tracking(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.shipstation.com/v2/tracking*' => fn (Request $request) => Http::response(['tracking_number' => $request['tracking_number'], 'carrier_code' => 'ups', 'status_code' => 'IT', 'events' => [['status_code' => 'AC', 'occurred_at' => '2026-10-23T10:00:00Z']]])]);
    }
}
