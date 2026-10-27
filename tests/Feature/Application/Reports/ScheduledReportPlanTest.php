<?php

namespace Tests\Feature\Application\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunProductSyncReport;
use App\Application\Reports\RunShippingMarginReport;
use App\Application\Reports\ScheduledReportPlan;
use App\Models\ReportSchedule;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduledReportPlanTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('calendarBoundaries')]
    public function test_next_run_crosses_calendar_boundaries_without_replaying_the_current_slot(array $attributes, string $after, string $expected): void
    {
        $schedule = $this->schedule($attributes);

        $actual = app(ScheduledReportPlan::class)->nextRun($schedule, CarbonImmutable::parse($after));

        $this->assertSame($expected, $actual->format('Y-m-d H:i:s P'));
    }

    public static function calendarBoundaries(): array
    {
        return [
            'daily exact slot rolls to tomorrow' => [[], '2026-12-31T07:00:00Z', '2027-01-01 07:00:00 +00:00'],
            'daily before slot keeps today' => [[], '2026-12-31T06:59:59Z', '2026-12-31 07:00:00 +00:00'],
            'weekly exact Monday rolls one week' => [['frequency' => 'weekly', 'weekday' => 1], '2026-12-28T07:00:00Z', '2027-01-04 07:00:00 +00:00'],
            'weekly later Monday remains today' => [['frequency' => 'weekly', 'weekday' => 1], '2026-12-28T06:00:00Z', '2026-12-28 07:00:00 +00:00'],
            'Sunday uses zero weekday' => [['frequency' => 'weekly', 'weekday' => 0], '2026-10-24T12:00:00Z', '2026-10-25 07:00:00 +00:00'],
            'monthly across leap February' => [['frequency' => 'monthly', 'month_day' => 28], '2028-02-28T07:00:00Z', '2028-03-28 07:00:00 +00:00'],
            'monthly current month before slot' => [['frequency' => 'monthly', 'month_day' => 28], '2026-02-27T23:59:59Z', '2026-02-28 07:00:00 +00:00'],
            'local date is already next year' => [['timezone' => 'Pacific/Auckland'], '2026-12-31T19:00:00Z', '2027-01-01 18:00:00 +00:00'],
            'US spring DST changes UTC run hour' => [['timezone' => 'America/New_York', 'time' => '07:00'], '2026-03-07T12:00:00Z', '2026-03-08 11:00:00 +00:00'],
            'European spring DST changes UTC run hour' => [['timezone' => 'Europe/Sofia', 'time' => '07:00'], '2026-03-28T05:00:00Z', '2026-03-29 04:00:00 +00:00'],
            'Sofia fall DST changes UTC run hour' => [['timezone' => 'Europe/Sofia', 'time' => '07:00'], '2026-10-24T04:00:00Z', '2026-10-25 05:00:00 +00:00'],
            'US spring gap time moves to the first valid minute' => [['timezone' => 'America/New_York', 'time' => '02:30'], '2026-03-08T05:00:00Z', '2026-03-08 07:30:00 +00:00'],
            'US spring gap slot already passed rolls to next day' => [['timezone' => 'America/New_York', 'time' => '02:30'], '2026-03-08T07:30:00Z', '2026-03-09 06:30:00 +00:00'],
            'US fall ambiguous time runs once on the first occurrence' => [['timezone' => 'America/New_York', 'time' => '01:30'], '2026-11-01T04:00:00Z', '2026-11-01 05:30:00 +00:00'],
            'US fall second occurrence of the slot does not replay it' => [['timezone' => 'America/New_York', 'time' => '01:30'], '2026-11-01T05:30:00Z', '2026-11-02 06:30:00 +00:00'],
            'US fall after the repeated hour rolls to next day' => [['timezone' => 'America/New_York', 'time' => '01:30'], '2026-11-01T06:45:00Z', '2026-11-02 06:30:00 +00:00'],
            'weekly exact slot across US spring DST' => [['timezone' => 'America/New_York', 'time' => '07:00', 'frequency' => 'weekly', 'weekday' => 0], '2026-03-01T12:00:00Z', '2026-03-08 11:00:00 +00:00'],
            'weekly slot right after US spring DST keeps the local hour' => [['timezone' => 'America/New_York', 'time' => '07:00', 'frequency' => 'weekly', 'weekday' => 0], '2026-03-08T11:00:00Z', '2026-03-15 11:00:00 +00:00'],
            'weekly exact slot across US fall DST' => [['timezone' => 'America/New_York', 'time' => '07:00', 'frequency' => 'weekly', 'weekday' => 0], '2026-10-25T11:00:00Z', '2026-11-01 12:00:00 +00:00'],
            'weekly after the slot on the same weekday rolls a full week' => [['frequency' => 'weekly', 'weekday' => 1], '2026-12-28T07:00:01Z', '2027-01-04 07:00:00 +00:00'],
            'monthly slot later on the European fall DST day' => [['timezone' => 'Europe/Sofia', 'time' => '07:00', 'frequency' => 'monthly', 'month_day' => 25], '2026-10-25T04:00:00Z', '2026-10-25 05:00:00 +00:00'],
            'monthly exact slot on the European fall DST day' => [['timezone' => 'Europe/Sofia', 'time' => '07:00', 'frequency' => 'monthly', 'month_day' => 25], '2026-10-25T05:00:00Z', '2026-11-25 05:00:00 +00:00'],
            'daily gap time created later on the spring DST day is not shifted to the next day' => [['timezone' => 'America/New_York', 'time' => '02:30'], '2026-03-08T16:00:00Z', '2026-03-09 06:30:00 +00:00'],
        ];
    }

    public function test_rolling_dates_use_schedule_timezone_and_preserve_non_date_filters_without_mutation(): void
    {
        $schedule = $this->schedule(['timezone' => 'America/Los_Angeles', 'uses_date_range' => true, 'lookback_days' => 3, 'end_offset_days' => 1, 'arguments' => ['old-start', 'old-end', 12.5], 'parameters' => ['start_date' => 'old-start', 'end_date' => 'old-end', 'threshold' => 12.5]]);

        $actual = app(ScheduledReportPlan::class)->inputs($schedule, CarbonImmutable::parse('2026-01-01T00:30:00Z'));

        $this->assertSame(['2025-12-28', '2025-12-30', 12.5], $actual['arguments']);
        $this->assertSame(['start_date' => '2025-12-28', 'end_date' => '2025-12-30', 'threshold' => 12.5], $actual['parameters']);
        $this->assertSame('2025-12-28', $actual['start']);
        $this->assertSame('2025-12-30', $actual['end']);
        $this->assertSame(['old-start', 'old-end', 12.5], $schedule->arguments);
        $this->assertSame('old-end', $schedule->parameters['end_date']);
    }

    public function test_one_day_window_and_zero_offset_include_only_the_current_local_day(): void
    {
        $schedule = $this->schedule(['timezone' => 'Pacific/Auckland', 'uses_date_range' => true, 'lookback_days' => 1, 'end_offset_days' => 0]);

        $actual = app(ScheduledReportPlan::class)->inputs($schedule, CarbonImmutable::parse('2026-12-31T12:30:00Z'));

        $this->assertSame('2027-01-01', $actual['start']);
        $this->assertSame('2027-01-01', $actual['end']);
        $this->assertSame(['2027-01-01', '2027-01-01'], $actual['arguments']);
    }

    #[DataProvider('clockDependentTools')]
    public function test_clock_dependent_inputs_are_refreshed_instead_of_replaying_saved_time(string $tool, array $arguments, array $expected): void
    {
        $schedule = $this->schedule(['tool' => $tool, 'arguments' => $arguments]);
        $now = CarbonImmutable::parse('2026-10-25T12:00:00Z');

        $actual = app(ScheduledReportPlan::class)->inputs($schedule, $now);

        $this->assertSame($expected, $actual['arguments']);
        $this->assertNull($actual['start']);
        $this->assertNull($actual['end']);
    }

    public static function clockDependentTools(): array
    {
        return [
            'gift cards preserve horizon' => ['gift_cards', [21, 123], [21, 1792929600]],
            'disputes refresh now' => ['disputes', [123, 'saved-filter'], [1792929600, 'saved-filter']],
            'tag audit refreshes ninety day cutoff' => ['tag_audit', ['start', 'end', 'old'], ['start', 'end', '2026-07-27']],
        ];
    }

    public function test_tag_policy_uses_current_policy_but_does_not_overwrite_saved_model_arguments(): void
    {
        $currentPolicy = ['required' => [['name' => 'Priority', 'when' => ['vip'], 'must_have' => ['priority']]]];
        config()->set('tag-policy', $currentPolicy);
        $schedule = $this->schedule(['tool' => 'tag_policy', 'arguments' => ['start', 'end', ['old' => true]]]);

        $actual = app(ScheduledReportPlan::class)->inputs($schedule, CarbonImmutable::parse('2026-10-25T12:00:00Z'));

        $this->assertSame(['start', 'end', $currentPolicy], $actual['arguments']);
        $this->assertSame(['start', 'end', ['old' => true]], $schedule->arguments);
    }

    #[DataProvider('productModes')]
    public function test_only_complete_catalog_product_sync_runs_can_be_used_as_schedule_templates(?string $mode, bool $expected): void
    {
        $store = Store::factory()->create();
        $run = app(QueuedReportRunner::class)->createRun($store, 'product_sync', RunProductSyncReport::class, [], null, null);
        $run->forceFill(['parameters' => ['mode' => $mode]])->save();
        $run->storeResult(new ReportResult(rows: [], scanned: 0));

        $actual = app(ScheduledReportPlan::class)->supports($run);

        $this->assertSame($expected, $actual);
    }

    public static function productModes(): array
    {
        return ['catalog' => ['catalog', true], 'shipment' => ['shipment', false], 'missing mode' => [null, false]];
    }

    public function test_completed_run_without_stored_result_is_not_a_schedule_template(): void
    {
        $store = Store::factory()->create();
        $run = app(QueuedReportRunner::class)->createRun($store, 'shipping_margin', RunShippingMarginReport::class, [], null, null);
        $run->forceFill(['status' => 'completed', 'result' => null])->save();

        $this->assertFalse(app(ScheduledReportPlan::class)->supports($run));
    }

    public function test_unconfigured_integrations_can_be_inspected_but_cannot_be_executed(): void
    {
        $store = Store::factory()->make(['shopify_access_token' => null]);
        $schedule = $this->schedule(['tool' => 'shipping_margin']);
        $plan = app(ScheduledReportPlan::class);
        $this->assertSame(RunShippingMarginReport::class, $plan->action($schedule, $store, requireCredentials: false));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The report integration is not configured.');
        $plan->action($schedule, $store);
    }

    public function test_unknown_tool_cannot_be_executed_even_when_credential_check_is_bypassed(): void
    {
        $schedule = $this->schedule(['tool' => 'unregistered_tool']);
        $store = Store::factory()->make();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This report cannot be scheduled.');
        app(ScheduledReportPlan::class)->action($schedule, $store, requireCredentials: false);
    }

    /** @param array<string, mixed> $attributes */
    private function schedule(array $attributes = []): ReportSchedule
    {
        return ReportSchedule::factory()->make(['store_id' => 1, 'user_id' => 1, ...$attributes]);
    }
}
