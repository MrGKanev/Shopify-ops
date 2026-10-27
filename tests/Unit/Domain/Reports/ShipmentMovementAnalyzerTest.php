<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ShipmentMovementAnalyzer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShipmentMovementAnalyzerTest extends TestCase
{
    #[DataProvider('thresholdBoundaries')]
    public function test_elapsed_hours_trigger_findings_at_the_exact_threshold(string $status, string $timestamp, string $expected, int $hours): void
    {
        $events = $status === 'NY' ? [] : [['status_code' => 'IT', 'occurred_at' => $timestamp]];
        $label = $status === 'NY' ? $timestamp : '2026-10-01T00:00:00Z';

        $actual = (new ShipmentMovementAnalyzer)->analyze(['status_code' => $status, 'events' => $events], $label, 48, 72, CarbonImmutable::parse('2026-10-25T12:00:00Z'));

        $this->assertSame($expected, $actual['classification']);
        $this->assertSame($hours, $actual['hours']);
    }

    public static function thresholdBoundaries(): array
    {
        return [
            'acceptance one second before' => ['NY', '2026-10-23T12:00:01Z', 'acceptance_grace', 47],
            'acceptance exactly forty eight hours' => ['NY', '2026-10-23T12:00:00Z', 'no_acceptance_record', 48],
            'progress one second before' => ['IT', '2026-10-22T12:00:01Z', 'recent_progress', 71],
            'progress exactly seventy two hours' => ['IT', '2026-10-22T12:00:00Z', 'no_recent_progress', 72],
        ];
    }

    public function test_out_of_order_duplicate_events_keep_the_earliest_explicit_acceptance_and_latest_progress(): void
    {
        $events = [
            ['status_code' => 'IT', 'occurred_at' => '2026-10-25T11:00:00Z'],
            ['status_code' => 'AC', 'occurred_at' => '2026-10-23T09:00:00Z'],
            ['status_code' => 'AC', 'occurred_at' => '2026-10-23T08:00:00Z'],
            ['status_code' => 'AC', 'occurred_at' => '2026-10-23T08:00:00Z'],
        ];

        $actual = $this->analyze(['status_code' => 'IT', 'events' => $events]);

        $this->assertSame('2026-10-23T08:00:00+00:00', $actual['accepted_at']);
        $this->assertSame('2026-10-23T08:00:00+00:00', $actual['carrier_acceptance_at']);
        $this->assertSame('2026-10-25T11:00:00+00:00', $actual['last_progress_at']);
        $this->assertSame(1, $actual['hours']);
        $this->assertSame('recent_progress', $actual['classification']);
    }

    public function test_in_transit_evidence_does_not_invent_an_explicit_carrier_acceptance_event(): void
    {
        $actual = $this->analyze(['status_code' => 'IT', 'events' => [['status_code' => 'IT', 'occurred_at' => '2026-10-25T10:00:00Z']]]);

        $this->assertSame('2026-10-25T10:00:00+00:00', $actual['accepted_at']);
        $this->assertNull($actual['carrier_acceptance_at']);
        $this->assertTrue($actual['resolves']);
    }

    #[DataProvider('invalidEvidence')]
    public function test_invalid_evidence_never_resolves_or_publishes_explicit_acceptance(mixed $invalidEvent): void
    {
        $actual = $this->analyze(['status_code' => 'DE', 'events' => [['status_code' => 'AC', 'occurred_at' => '2026-10-24T10:00:00Z'], $invalidEvent]]);

        $this->assertSame('movement_unknown', $actual['classification']);
        $this->assertFalse($actual['actionable']);
        $this->assertFalse($actual['resolves']);
        $this->assertNull($actual['carrier_acceptance_at']);
    }

    public static function invalidEvidence(): array
    {
        return [
            'malformed event' => ['broken'],
            'missing date' => [['status_code' => 'IT']],
            'impossible calendar date' => [['status_code' => 'IT', 'occurred_at' => '2026-02-30T10:00:00Z']],
            'future event' => [['status_code' => 'IT', 'occurred_at' => '2026-10-25T12:00:01Z']],
            'event predates label' => [['status_code' => 'AC', 'occurred_at' => '2026-09-30T23:59:59Z']],
            'date-only event' => [['status_code' => 'IT', 'occurred_at' => '2026-10-24']],
        ];
    }

    public function test_label_without_timezone_uses_shipstation_pacific_time_across_daylight_saving(): void
    {
        $actual = (new ShipmentMovementAnalyzer)->analyze(['status_code' => 'NY', 'events' => []], '2026-11-01T00:30:00', 48, 72, CarbonImmutable::parse('2026-11-03T07:30:00Z'));

        $this->assertSame('no_acceptance_record', $actual['classification']);
        $this->assertSame(48, $actual['hours']);
    }

    public function test_offset_timestamps_are_compared_as_instants_instead_of_local_clock_text(): void
    {
        $actual = $this->analyze(['status_code' => 'IT', 'events' => [['status_code' => 'AC', 'occurred_at' => '2026-10-25T13:00:00+03:00'], ['status_code' => 'IT', 'occurred_at' => '2026-10-25T06:30:00-04:00']]]);

        $this->assertSame('2026-10-25T13:00:00+03:00', $actual['carrier_acceptance_at']);
        $this->assertSame('2026-10-25T06:30:00-04:00', $actual['last_progress_at']);
        $this->assertSame(1, $actual['hours']);
    }

    #[DataProvider('invalidDeliveryDates')]
    public function test_delivered_status_without_valid_current_delivery_evidence_remains_unconfirmed(string $date): void
    {
        $actual = $this->analyze(['status_code' => 'DE', 'events' => [], 'actual_delivery_date' => $date]);

        $this->assertSame('movement_unknown', $actual['classification']);
        $this->assertFalse($actual['resolves']);
    }

    public static function invalidDeliveryDates(): array
    {
        return ['before label' => ['2026-09-30T12:00:00Z'], 'future' => ['2026-10-26T12:00:00Z'], 'missing timezone' => ['2026-10-24T12:00:00'], 'invalid calendar' => ['2026-02-30T12:00:00Z']];
    }

    #[DataProvider('statusesWithFutureLabel')]
    public function test_a_label_created_after_the_check_time_is_never_classified(string $status): void
    {
        $actual = (new ShipmentMovementAnalyzer)->analyze(['status_code' => $status, 'events' => [], 'actual_delivery_date' => '2026-10-25T11:00:00Z'], '2026-10-25T12:00:01Z', 48, 72, CarbonImmutable::parse('2026-10-25T12:00:00Z'));

        $this->assertSame('movement_unknown', $actual['classification']);
        $this->assertFalse($actual['actionable']);
        $this->assertFalse($actual['resolves']);
        $this->assertNull($actual['carrier_acceptance_at']);
    }

    public static function statusesWithFutureLabel(): array
    {
        return ['not yet' => ['NY'], 'exception' => ['EX'], 'delivered' => ['DE'], 'in transit' => ['IT']];
    }

    public function test_delivered_without_events_accepts_a_delivery_date_exactly_at_the_label_and_at_the_check_time(): void
    {
        foreach (['2026-10-01T00:00:00Z', '2026-10-25T12:00:00Z'] as $deliveredAt) {
            $actual = $this->analyze(['status_code' => 'DE', 'events' => [], 'actual_delivery_date' => $deliveredAt]);

            $this->assertSame('delivered', $actual['classification']);
            $this->assertTrue($actual['resolves']);
        }
    }

    /** @param array<string, mixed> $tracking
     * @return array<string, mixed> */
    private function analyze(array $tracking): array
    {
        return (new ShipmentMovementAnalyzer)->analyze($tracking, '2026-10-01T00:00:00Z', 48, 72, CarbonImmutable::parse('2026-10-25T12:00:00Z'));
    }
}
