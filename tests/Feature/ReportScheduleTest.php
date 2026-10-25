<?php

namespace Tests\Feature;

use App\Application\Reports\DispatchScheduledReport;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunOnHoldStallReport;
use App\Application\Reports\RunShippingMarginReport;
use App\Jobs\RunQueuedReport;
use App\Models\ReportSchedule;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportScheduleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_administrators_can_manage_schedules(): void
    {
        $this->get('/admin/report-schedules')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/admin/report-schedules')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/admin/report-schedules', $this->input())->assertForbidden();
        [$admin] = $this->admin();

        $this->actingAs($admin)->get('/admin/report-schedules')->assertSeeText('No report schedules yet.');
        $this->get('/admin/report-schedules/create')->assertSeeText('Enable automatic execution');
    }

    public function test_backlog_schedule_defaults_to_paused_and_can_be_enabled_and_stopped_independently(): void
    {
        $this->travelTo('2026-10-23 04:00:00 UTC');
        [$admin, $store] = $this->admin();

        $this->actingAs($admin)->post('/admin/report-schedules', $this->input())->assertRedirect(route('admin.report-schedules.index'));

        $schedule = $store->reportSchedules()->sole();
        $this->assertFalse($schedule->enabled);
        $this->assertNull($schedule->next_run_at);
        $this->assertFalse((bool) $store->scheduled_audit_enabled);
        $this->get('/admin/report-schedules')->assertSeeText('Paused');
        $this->get(route('admin.report-schedules.edit', $schedule))->assertSeeText('Backlog history preserves');

        $this->post(route('admin.report-schedules.toggle', $schedule), ['enabled' => 1])->assertRedirect();

        $this->assertSame('2026-10-23 04:30:00', $schedule->fresh()->next_run_at->format('Y-m-d H:i:s'));
        $this->post(route('admin.report-schedules.toggle', $schedule), ['enabled' => 0])->assertRedirect();
        $this->assertNull($schedule->fresh()->next_run_at);
    }

    public function test_schedule_copies_validated_filters_and_never_accepts_a_client_supplied_action(): void
    {
        [$admin, $store] = $this->admin();
        $source = app(QueuedReportRunner::class)->createRun($store, 'shipping_margin', RunShippingMarginReport::class, ['2026-10-01', '2026-10-20', 12.5], '2026-10-01', '2026-10-20', $admin->id);
        $source->forceFill(['parameters' => ['start_date' => '2026-10-01', 'end_date' => '2026-10-20', 'threshold' => 12.5]])->save();
        $source->storeResult(new ReportResult(rows: [], scanned: 0, pages: 0, truncated: false, params: [], meta: []));

        $this->actingAs($admin)->get(route('admin.report-schedules.create', ['source_run_id' => $source->id]))->assertSeeText('Saved filters')->assertSeeText('12.5');
        $this->post('/admin/report-schedules', $this->input(['source_run_id' => $source->id, 'tool' => null, 'report' => 'arbitrary', 'arguments' => ['untrusted']]))->assertRedirect();

        $schedule = $store->reportSchedules()->sole();
        $this->assertSame('shipping_margin', $schedule->tool);
        $this->assertSame(['2026-10-01', '2026-10-20', 12.5], $schedule->arguments);
        $this->assertSame(12.5, $schedule->parameters['threshold']);
        $this->assertStringNotContainsString('start_date', $schedule->getRawOriginal('parameters'));
        $this->put(route('admin.report-schedules.update', $schedule), $this->input(['name' => 'Updated', 'tool' => 'run_audit', 'arguments' => []]))->assertRedirect();
        $this->assertSame('shipping_margin', $schedule->fresh()->tool);
        $this->assertSame(['2026-10-01', '2026-10-20', 12.5], $schedule->fresh()->arguments);
    }

    public function test_foreign_schedules_and_source_runs_are_not_disclosed(): void
    {
        [$admin, $store] = $this->admin();
        $foreign = ReportSchedule::factory()->create();
        $source = app(QueuedReportRunner::class)->createRun($foreign->store, 'shipping_margin', RunShippingMarginReport::class, [], null, null);
        $this->actingAs($admin)->get(route('admin.report-schedules.edit', $foreign))->assertNotFound();
        $this->post(route('admin.report-schedules.toggle', $foreign), ['enabled' => 1])->assertNotFound();
        $this->post(route('admin.report-schedules.run', $foreign))->assertNotFound();
        $this->get(route('admin.report-schedules.create', ['source_run_id' => $source->id]))->assertNotFound();
        $this->post('/admin/report-schedules', $this->input(['source_run_id' => $source->id]))->assertNotFound();
        $this->assertSame(0, $store->reportSchedules()->count());
    }

    #[DataProvider('invalidTemplates')]
    public function test_incomplete_cursor_and_unapproved_templates_are_rejected(bool $truncated, ?string $cursor, string $action): void
    {
        [$admin, $store] = $this->admin();
        $source = app(QueuedReportRunner::class)->createRun($store, 'shipping_margin', $action, ['2026-10-01', '2026-10-20', 12.5], '2026-10-01', '2026-10-20', $admin->id);
        $source->forceFill(['parameters' => ['after' => $cursor]])->save();
        $source->storeResult(new ReportResult(rows: [], scanned: 0, pages: 0, truncated: $truncated, params: [], meta: []));

        $this->actingAs($admin)->post('/admin/report-schedules', $this->input(['source_run_id' => $source->id]))->assertSessionHasErrors('source_run_id');

        $this->assertDatabaseCount('report_schedules', 0);
    }

    public static function invalidTemplates(): array
    {
        return ['partial' => [true, null, RunShippingMarginReport::class], 'cursor' => [false, 'cursor', RunShippingMarginReport::class], 'wrong action' => [false, null, \stdClass::class]];
    }

    public function test_pausing_cancels_queued_automatic_work_but_preserves_manual_work_and_escapes_names(): void
    {
        $this->travelTo('2026-10-23 08:00:00 UTC');
        [$admin, $store] = $this->admin();
        $schedule = ReportSchedule::factory()->for($store)->create(['user_id' => $admin->id, 'enabled' => true, 'next_run_at' => now(), 'name' => '<script>unsafe</script>']);
        Queue::fake([RunQueuedReport::class]);
        $automatic = app(DispatchScheduledReport::class)->handle($schedule);

        $this->actingAs($admin)->post(route('admin.report-schedules.toggle', $schedule), ['enabled' => 0])->assertRedirect();

        $this->assertSame('cancelled', $automatic->fresh()->status);
        $this->get('/admin/report-schedules')->assertSeeText('<script>unsafe</script>')->assertDontSee('<script>unsafe</script>', false);
        $this->post(route('admin.report-schedules.run', $schedule))->assertRedirect();
        $manual = $schedule->runs()->latest()->first();
        $this->post(route('admin.report-schedules.toggle', $schedule), ['enabled' => 0])->assertRedirect();
        $this->assertSame('queued', $manual->fresh()->status);
        Queue::assertPushed(RunQueuedReport::class, 2);
    }

    public function test_notifications_require_a_recipient_and_inventory_forecast_preserves_its_fixed_window(): void
    {
        [$admin, $store] = $this->admin();
        $this->actingAs($admin)->post('/admin/report-schedules', $this->input(['notification_enabled' => true]))->assertSessionHasErrors('notification_email');
        $schedule = ReportSchedule::factory()->for($store)->create(['user_id' => $admin->id, 'tool' => 'inventory_forecast', 'uses_date_range' => true]);

        $this->put(route('admin.report-schedules.update', $schedule), $this->input(['lookback_days' => 10]))->assertSessionHasErrors('lookback_days');

        $this->assertSame(30, $schedule->fresh()->lookback_days);
    }

    public function test_manual_run_works_while_paused_and_deduplicates_pending_work(): void
    {
        $this->travelTo('2026-10-23 08:00:00 UTC');
        [$admin, $store] = $this->admin();
        $schedule = ReportSchedule::factory()->for($store)->create(['user_id' => $admin->id]);
        Queue::fake([RunQueuedReport::class]);

        $this->actingAs($admin)->post(route('admin.report-schedules.run', $schedule))->assertRedirect();
        $this->post(route('admin.report-schedules.run', $schedule))->assertSessionHasErrors('schedule');

        $run = $schedule->runs()->sole();
        $this->assertNull($run->scheduled_for);
        $this->assertNull($schedule->fresh()->next_run_at);
        Queue::assertPushed(RunQueuedReport::class, 1, fn (RunQueuedReport $job): bool => $job->reportRunId === $run->id);
    }

    #[DataProvider('reportResultTemplates')]
    public function test_shared_result_component_offers_scheduling_for_different_reports(string $tool, string $action, string $route, array $arguments, array $parameters, array $meta): void
    {
        [$admin, $store] = $this->admin();
        $run = app(QueuedReportRunner::class)->createRun($store, $tool, $action, $arguments, '2026-10-01', '2026-10-20', $admin->id);
        $run->forceFill(['parameters' => $parameters])->save();
        $run->storeResult(new ReportResult(rows: [], scanned: 0, pages: 1, truncated: false, params: ['startDate' => '2026-10-01', 'endDate' => '2026-10-20', 'threshold' => 15], meta: $meta));

        $this->actingAs($admin)->get(route($route, [...$parameters, 'report_run' => $run->id]))->assertSeeText('Schedule with these filters')->assertSee(route('admin.report-schedules.create', ['source_run_id' => $run->id]), false);
    }

    public static function reportResultTemplates(): array
    {
        return [
            'on hold' => ['on_hold_stall', RunOnHoldStallReport::class, 'reports.on-hold-stall.result', ['2026-10-01', '2026-10-20'], ['start_date' => '2026-10-01', 'end_date' => '2026-10-20'], []],
            'shipping margin' => ['shipping_margin', RunShippingMarginReport::class, 'reports.shipping-margin.result', ['2026-10-01', '2026-10-20', 15], ['start_date' => '2026-10-01', 'end_date' => '2026-10-20', 'threshold' => 15], ['byCarrier' => [], 'shopifyPages' => 1, 'shopifyTruncated' => false]],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_configuration_is_rejected(string $field, mixed $value): void
    {
        [$admin] = $this->admin();

        $this->actingAs($admin)->post('/admin/report-schedules', $this->input([$field => $value]))->assertSessionHasErrors($field);

        $this->assertDatabaseCount('report_schedules', 0);
    }

    public static function invalidInputs(): array
    {
        return ['frequency' => ['frequency', 'hourly'], 'clock' => ['time', '25:00'], 'timezone' => ['timezone', 'Invalid/Zone'], 'month day' => ['month_day', 31], 'period' => ['lookback_days', 0], 'threshold' => ['notification_threshold', 0], 'email' => ['notification_email', 'bad'], 'boolean' => ['enabled', 'invalid']];
    }

    /** @return array{User, Store} */
    private function admin(): array
    {
        $store = Store::factory()->create();
        $user = User::factory()->admin()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['name' => 'Daily backlog', 'tool' => 'shipment_backlog', 'enabled' => false, 'frequency' => 'daily', 'time' => '07:30', 'timezone' => 'Europe/Sofia', 'weekday' => 1, 'month_day' => 1, 'lookback_days' => 30, 'end_offset_days' => 1, 'notification_enabled' => false, 'notification_threshold' => 1, 'notification_email' => null], $overrides);
    }
}
