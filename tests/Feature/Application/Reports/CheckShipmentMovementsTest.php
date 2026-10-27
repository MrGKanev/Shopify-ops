<?php

namespace Tests\Feature\Application\Reports;

use App\Application\Reports\CheckShipmentMovements;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationTrackingClient;
use App\IssueStatus;
use App\Models\ShipmentMovementObservation;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckShipmentMovementsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $this->store = Store::factory()->create(['store_number' => '42', 'shipstation_api_key' => 'key', 'shipstation_v2_api_key' => 'v2']);
    }

    /** @return array<string, mixed> */
    private function label(int $id = 1, array $overrides = []): array
    {
        return $overrides + ['shipmentId' => $id, 'orderNumber' => '1001', 'carrierCode' => 'ups', 'trackingNumber' => 'TRACK-'.$id, 'createDate' => '2026-10-10T12:00:00Z'];
    }

    /** @return array<string, mixed> */
    private function tracking(string $status, array $events = []): array
    {
        return ['status_code' => $status, 'events' => $events, 'actual_delivery_date' => null];
    }

    private function trackingReturns(array ...$responses): MockInterface
    {
        $client = $this->mock(ShipStationTrackingClient::class);
        $client->shouldReceive('track')->andReturnValues($responses);

        return $client;
    }

    private function check(array $shipments, int $acceptanceHours = 48, int $staleHours = 72): void
    {
        app(CheckShipmentMovements::class)->handle($this->store, $shipments, $acceptanceHours, $staleHours);
    }

    private function observation(array $shipment): ShipmentMovementObservation
    {
        return $this->store->shipmentMovementObservations()->where('identity', ShipmentMovementObservation::shipmentIdentity($this->store, $shipment))->sole();
    }

    public function test_ten_labels_are_checked_but_eleven_are_rejected_before_any_tracking_read(): void
    {
        $ten = array_map(fn (int $id): array => $this->label($id), range(1, 10));
        $this->trackingReturns(...array_fill(0, 10, $this->tracking('IT', [['status_code' => 'IT', 'occurred_at' => '2026-10-24T08:00:00Z']])));
        $this->check($ten);
        $this->assertSame(10, $this->store->shipmentMovementObservations()->count());

        $this->mock(ShipStationTrackingClient::class)->shouldNotReceive('track');
        $this->expectException(UnexpectedResponse::class);
        $this->check(array_map(fn (int $id): array => $this->label($id), range(1, 11)));
    }

    public function test_the_same_carrier_and_tracking_number_on_two_active_labels_is_rejected_but_a_voided_duplicate_is_ignored(): void
    {
        $this->trackingReturns($this->tracking('IT', [['status_code' => 'IT', 'occurred_at' => '2026-10-24T08:00:00Z']]));
        $this->check([$this->label(1), $this->label(2, ['trackingNumber' => 'TRACK-1', 'voidDate' => '2026-10-11T00:00:00Z'])]);
        $this->assertSame(1, $this->store->shipmentMovementObservations()->count());

        $this->mock(ShipStationTrackingClient::class)->shouldNotReceive('track');
        $this->expectException(UnexpectedResponse::class);
        $this->check([$this->label(1), $this->label(2, ['trackingNumber' => 'TRACK-1'])]);
    }

    public function test_the_same_tracking_number_under_different_carriers_is_two_labels(): void
    {
        $this->trackingReturns($this->tracking('NY'), $this->tracking('NY'));

        $this->check([$this->label(1), $this->label(2, ['carrierCode' => 'fedex', 'trackingNumber' => 'TRACK-1'])]);

        $this->assertSame(2, $this->store->shipmentMovementObservations()->count());
    }

    #[DataProvider('unusableCarrierOrTracking')]
    public function test_a_missing_or_malformed_carrier_or_tracking_number_fails_the_whole_check(array $overrides): void
    {
        $this->mock(ShipStationTrackingClient::class)->shouldNotReceive('track');
        try {
            $this->check([$this->label(1), $this->label(2, $overrides)]);
            $this->fail('A label without a usable carrier and tracking number must be rejected.');
        } catch (UnexpectedResponse) {
            $this->assertSame(0, $this->store->shipmentMovementObservations()->count());
            $this->assertSame(0, $this->store->operationalIssues()->count());
        }
    }

    public static function unusableCarrierOrTracking(): array
    {
        return [
            'carrier absent' => [['carrierCode' => null]],
            'carrier empty' => [['carrierCode' => '']],
            'carrier uppercase' => [['carrierCode' => 'UPS']],
            'carrier with separator' => [['carrierCode' => 'ups-ground']],
            'carrier not a string' => [['carrierCode' => 7]],
            'tracking blank' => [['trackingNumber' => '   ']],
            'tracking not a string' => [['trackingNumber' => 12345]],
            'tracking too long' => [['trackingNumber' => str_repeat('1', 256)]],
        ];
    }

    public function test_a_stale_observation_from_an_older_check_cannot_overwrite_a_newer_one(): void
    {
        $label = $this->label();
        $this->trackingReturns($this->tracking('NY'));
        $this->check([$label]);
        $this->assertSame(IssueStatus::Open, $this->store->operationalIssues()->sole()->status);
        $observation = $this->observation($label);
        $observation->forceFill(['classification' => 'recent_progress', 'checked_at' => now()->addHour()])->save();
        $this->store->operationalIssues()->sole()->update(['status' => IssueStatus::Resolved]);

        $this->trackingReturns($this->tracking('EX'));
        $this->check([$label]);

        $this->assertSame('recent_progress', $observation->fresh()->classification);
        $this->assertTrue($observation->fresh()->checked_at->equalTo(now()->addHour()));
        $this->assertSame(IssueStatus::Resolved, $this->store->operationalIssues()->sole()->status);
    }

    public function test_carrier_acceptance_only_moves_to_an_earlier_time(): void
    {
        $label = $this->label();
        $accept = fn (string $at): array => $this->tracking('IT', [['status_code' => 'AC', 'occurred_at' => $at], ['status_code' => 'IT', 'occurred_at' => '2026-10-24T08:00:00Z']]);
        $this->trackingReturns($accept('2026-10-14T12:00:00Z'));
        $this->check([$label]);
        $this->assertSame('2026-10-14T12:00:00+00:00', $this->observation($label)->carrier_acceptance_at->toIso8601String());

        $this->travel(1)->minute();
        $this->trackingReturns($accept('2026-10-16T12:00:00Z'));
        $this->check([$label]);
        $this->assertSame('2026-10-14T12:00:00+00:00', $this->observation($label)->carrier_acceptance_at->toIso8601String());

        $this->travel(1)->minute();
        $this->trackingReturns($accept('2026-10-12T12:00:00Z'));
        $this->check([$label]);
        $this->assertSame('2026-10-12T12:00:00+00:00', $this->observation($label)->carrier_acceptance_at->toIso8601String());

        $this->travel(1)->minute();
        $this->trackingReturns($this->tracking('IT', [['status_code' => 'IT', 'occurred_at' => '2026-10-24T08:00:00Z']]));
        $this->check([$label]);
        $this->assertSame('2026-10-12T12:00:00+00:00', $this->observation($label)->carrier_acceptance_at->toIso8601String());
    }

    public function test_an_unknown_classification_keeps_the_last_known_progress_time(): void
    {
        $label = $this->label();
        $this->trackingReturns($this->tracking('IT', [['status_code' => 'IT', 'occurred_at' => '2026-10-24T08:00:00Z']]));
        $this->check([$label]);
        $this->assertSame('2026-10-24T08:00:00+00:00', $this->observation($label)->last_progress_at->toIso8601String());

        $this->travel(1)->minute();
        $this->trackingReturns($this->tracking('UN', [['status_code' => 'IT', 'occurred_at' => '2026-10-24T09:00:00Z']]));
        $this->check([$label]);

        $observation = $this->observation($label);
        $this->assertSame('movement_unknown', $observation->classification);
        $this->assertSame('2026-10-24T08:00:00+00:00', $observation->last_progress_at->toIso8601String());

        $this->travel(1)->minute();
        $this->trackingReturns($this->tracking('IT', [['status_code' => 'IT', 'occurred_at' => '2026-10-24T09:30:00Z']]));
        $this->check([$label]);
        $this->assertSame('2026-10-24T09:30:00+00:00', $this->observation($label)->last_progress_at->toIso8601String());
    }
}
