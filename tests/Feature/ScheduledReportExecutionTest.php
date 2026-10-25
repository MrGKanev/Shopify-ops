<?php

namespace Tests\Feature;

use App\Application\Reports\DispatchScheduledReport;
use App\Application\Reports\RecordRun;
use App\Application\Reports\ScheduledReportPlan;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Jobs\RunQueuedReport;
use App\Models\ReportSchedule;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ReportEmailNotification;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ScheduledReportExecutionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_due_schedules_use_local_rolling_dates_and_queue_only_once(): void
    {
        $this->travelTo('2026-10-23 04:30:00 UTC');
        $schedule = $this->schedule(['enabled' => true, 'timezone' => 'Europe/Sofia', 'time' => '07:30', 'next_run_at' => now()]);
        ReportSchedule::factory()->create(['enabled' => false, 'next_run_at' => now()->subDay()]);
        Queue::fake([RunQueuedReport::class]);

        $this->artisan('reports:queue-scheduled-reports')->expectsOutput('Queued 1 scheduled report(s). 0 schedule(s) failed.')->assertSuccessful();
        $this->artisan('reports:queue-scheduled-reports')->expectsOutput('Queued 0 scheduled report(s). 0 schedule(s) failed.')->assertSuccessful();

        $run = $schedule->runs()->sole();
        $this->assertSame(['2026-09-23', '2026-10-22'], $run->arguments);
        $this->assertSame('2026-10-24 04:30:00', $schedule->fresh()->next_run_at->format('Y-m-d H:i:s'));
        Queue::assertPushed(RunQueuedReport::class, 1, fn (RunQueuedReport $job): bool => $job->reportRunId === $run->id);
    }

    public function test_success_notifies_once_using_schedule_rules_and_saved_result_never_rescans(): void
    {
        $this->travelTo('2026-10-23 08:00:00 UTC');
        $schedule = $this->schedule(['notification_enabled' => true, 'notification_threshold' => 1, 'notification_email' => 'scheduled@example.com']);
        $schedule->store->update(['email_rules' => ['on_hold_stall' => ['mode' => 'immediate', 'threshold' => 1, 'email' => 'legacy@example.com']]]);
        Queue::fake([RunQueuedReport::class]);
        Notification::fake();
        $this->orders();
        $run = app(DispatchScheduledReport::class)->handle($schedule, manual: true);

        (new RunQueuedReport($run->id))->handle(app(RecordRun::class));
        (new RunQueuedReport($run->id))->handle(app(RecordRun::class));

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(1, $schedule->store->runLogs()->count());
        $this->assertNull(Context::get('report_schedule_id'));
        Notification::assertSentOnDemand(ReportEmailNotification::class, fn ($notification, $channels, $recipient): bool => $recipient->routes['mail'] === 'scheduled@example.com');
        Notification::assertCount(1);
        $this->actingAs($schedule->user)->get(route('admin.report-schedules.result', [$schedule->id, $run->id]))->assertRedirect(route('reports.on-hold-stall.result', [...$run->parameters, 'report_run' => $run->id]));
        $this->get(route('reports.on-hold-stall.result', [...$run->parameters, 'report_run' => $run->id]))->assertSeeText('1 on-hold fulfillment orders');
        $this->travel(2)->days();
        $this->post(route('reports.on-hold-stall.export'), [...$run->parameters, 'report_run' => $run->id])->assertDownload();
    }

    #[DataProvider('notificationSettings')]
    public function test_no_email_is_sent_when_disabled_or_below_threshold(bool $enabled, int $threshold): void
    {
        $this->travelTo('2026-10-23 08:00:00 UTC');
        $schedule = $this->schedule(['notification_enabled' => $enabled, 'notification_threshold' => $threshold, 'notification_email' => 'scheduled@example.com']);
        $schedule->store->update(['email_rules' => ['on_hold_stall' => ['mode' => 'immediate', 'threshold' => 1, 'email' => 'legacy@example.com']]]);
        Notification::fake();
        Queue::fake([RunQueuedReport::class]);
        $this->orders();
        $run = app(DispatchScheduledReport::class)->handle($schedule, manual: true);

        (new RunQueuedReport($run->id))->handle(app(RecordRun::class));

        $this->assertSame('completed', $run->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_last_failure_remains_visible_after_stored_results_are_pruned(): void
    {
        $this->travelTo('2026-10-23 08:00:00 UTC');
        $schedule = $this->schedule();
        Queue::fake([RunQueuedReport::class]);
        $gateway = $this->mock(ShopifyOrders::class);
        $gateway->shouldReceive('onHoldFulfillmentCandidates')->once()->andThrow(new RuntimeException('private-token'));
        $run = app(DispatchScheduledReport::class)->handle($schedule, manual: true);
        (new RunQueuedReport($run->id))->handle(app(RecordRun::class));
        $run->delete();

        $this->actingAs($schedule->user)->get('/admin/report-schedules')->assertSeeText('Stored result has expired.')->assertSeeText('Report execution failed. Check run history.')->assertDontSeeText('private-token');

        $this->assertSame('failed', $schedule->fresh()->last_run_status);
    }

    public function test_fall_back_does_not_queue_twice_for_the_same_local_day(): void
    {
        $this->travelTo('2026-10-25 00:30:00 UTC');
        $schedule = $this->schedule(['enabled' => true, 'timezone' => 'Europe/Sofia', 'time' => '03:30', 'next_run_at' => now()]);
        Queue::fake([RunQueuedReport::class]);
        $this->artisan('reports:queue-scheduled-reports')->assertSuccessful();
        $this->travelTo('2026-10-25 01:30:00 UTC');

        $this->artisan('reports:queue-scheduled-reports')->expectsOutput('Queued 0 scheduled report(s). 0 schedule(s) failed.')->assertSuccessful();

        $this->assertSame('2026-10-26 01:30:00', $schedule->fresh()->next_run_at->format('Y-m-d H:i:s'));
        Queue::assertPushed(RunQueuedReport::class, 1);
    }

    public static function notificationSettings(): array
    {
        return ['off' => [false, 1], 'under threshold' => [true, 2]];
    }

    #[DataProvider('revocations')]
    public function test_stopped_automatic_runs_and_revoked_owners_do_not_execute(string $reason): void
    {
        $this->travelTo('2026-10-23 08:00:00 UTC');
        $schedule = $this->schedule(['enabled' => true, 'next_run_at' => now()]);
        Queue::fake([RunQueuedReport::class]);
        $run = app(DispatchScheduledReport::class)->handle($schedule);
        if ($reason === 'pause') {
            $schedule->update(['enabled' => false]);
        } elseif ($reason === 'membership') {
            $schedule->user->stores()->detach($schedule->store_id);
        } else {
            $schedule->user->update(['role' => UserRole::Viewer]);
        }
        Notification::fake();
        $gateway = $this->mock(ShopifyOrders::class);
        $gateway->shouldNotReceive('onHoldFulfillmentCandidates');

        (new RunQueuedReport($run->id))->handle(app(RecordRun::class));

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(0, $schedule->store->runLogs()->count());
        Notification::assertNothingSent();
    }

    public static function revocations(): array
    {
        return ['paused' => ['pause'], 'removed member' => ['membership'], 'removed role' => ['role']];
    }

    public function test_failure_is_visible_and_does_not_notify_or_expose_exception_details(): void
    {
        $this->travelTo('2026-10-23 08:00:00 UTC');
        $schedule = $this->schedule(['notification_enabled' => true, 'notification_email' => 'scheduled@example.com']);
        Queue::fake([RunQueuedReport::class]);
        Notification::fake();
        $gateway = $this->mock(ShopifyOrders::class);
        $gateway->shouldReceive('onHoldFulfillmentCandidates')->once()->andThrow(new RuntimeException('private-token'));
        $run = app(DispatchScheduledReport::class)->handle($schedule, manual: true);

        (new RunQueuedReport($run->id))->handle(app(RecordRun::class));

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($run->fresh()->result());
        $this->actingAs($schedule->user)->get('/admin/report-schedules')->assertSeeText('Report execution failed. Check run history.')->assertDontSeeText('private-token');
        Notification::assertNothingSent();
    }

    public function test_missing_credentials_fail_the_run_visibly_instead_of_recording_zero(): void
    {
        $this->travelTo('2026-10-23 08:00:00 UTC');
        $schedule = $this->schedule();
        $schedule->store->update(['shopify_access_token' => '']);
        Queue::fake([RunQueuedReport::class]);
        $run = app(DispatchScheduledReport::class)->handle($schedule, manual: true);

        (new RunQueuedReport($run->id))->handle(app(RecordRun::class));

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($run->fresh()->result());
    }

    #[DataProvider('calendarCases')]
    public function test_calendar_recurrence_uses_the_configured_timezone(string $frequency, string $after, string $expected): void
    {
        $schedule = new ReportSchedule(['frequency' => $frequency, 'time' => '07:30', 'timezone' => 'Europe/Sofia', 'weekday' => 1, 'month_day' => 15]);

        $next = app(ScheduledReportPlan::class)->nextRun($schedule, CarbonImmutable::parse($after));

        $this->assertSame($expected, $next->format('Y-m-d H:i:s'));
    }

    public static function calendarCases(): array
    {
        return [
            'daily before clock' => ['daily', '2026-10-23 04:00:00 UTC', '2026-10-23 04:30:00'],
            'daily after clock across DST' => ['daily', '2026-10-24 05:00:00 UTC', '2026-10-25 05:30:00'],
            'weekly Monday' => ['weekly', '2026-10-23 04:00:00 UTC', '2026-10-26 05:30:00'],
            'monthly next month' => ['monthly', '2026-10-23 04:00:00 UTC', '2026-11-15 05:30:00'],
        ];
    }

    public function test_legacy_daily_audit_is_superseded_even_by_a_paused_independent_audit_schedule(): void
    {
        $this->travelTo('2026-10-23 08:30:00 UTC');
        $schedule = $this->schedule(['tool' => 'run_audit', 'enabled' => false]);
        $schedule->store->update(['scheduled_audit_enabled' => true, 'scheduled_audit_time' => '08:30']);
        Queue::fake();

        $this->artisan('reports:queue-scheduled-audits')->expectsOutput('Queued 0 scheduled audit(s).')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_paused_operational_digest_schedule_prevents_legacy_digest_generation(): void
    {
        $this->travelTo('2026-10-23 08:00:00 UTC');
        $schedule = $this->schedule(['tool' => 'operational_digest', 'enabled' => false]);
        $schedule->store->update(['email_rules' => ['operational_digest' => ['mode' => 'digest', 'threshold' => 0, 'include_zero' => true, 'email' => 'legacy@example.com']]]);
        Queue::fake();
        Notification::fake();

        $this->artisan('reports:email-digest')->assertSuccessful();

        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    private function schedule(array $overrides = []): ReportSchedule
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);

        return ReportSchedule::factory()->for($store)->create(array_replace(['user_id' => $admin->id, 'tool' => 'on_hold_stall', 'arguments' => ['2026-09-01', '2026-09-30'], 'parameters' => ['start_date' => '2026-09-01', 'end_date' => '2026-09-30'], 'uses_date_range' => true], $overrides));
    }

    private function orders(): void
    {
        $gateway = $this->mock(ShopifyOrders::class);
        $gateway->shouldReceive('onHoldFulfillmentCandidates')->once()->andReturn(['fulfillment_orders' => [['order' => ['legacyResourceId' => '1', 'name' => '#1001', 'createdAt' => '2026-10-01T00:00:00Z', 'displayFinancialStatus' => 'PAID'], 'fulfillmentHolds' => [['reason' => 'MANUAL']]]], 'pages' => 1, 'truncated' => false]);
    }
}
