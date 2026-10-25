<?php

namespace Tests\Feature\Application\Reports;

use App\Application\Reports\DispatchScheduledReport;
use App\Application\Reports\RecordRun;
use App\Application\Reports\ReportResult;
use App\Jobs\RunQueuedReport;
use App\Models\ReportSchedule;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ScheduledReportFailureTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dispatch_failure_is_recorded_without_leaving_a_permanently_queued_run(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $schedule = $this->schedule(['enabled' => true, 'next_run_at' => now()->subMinute()]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('private-queue-token'));

        try {
            app(DispatchScheduledReport::class)->handle($schedule);
            $this->fail('Queue failure must propagate to the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('private-queue-token', $exception->getMessage());
        }

        $run = $schedule->runs()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->failure_reason);
        $this->assertSame('failed', $schedule->fresh()->last_run_status);
        $this->assertSame('2026-10-26 07:00:00', $schedule->fresh()->next_run_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('run_logs', 0);
    }

    #[DataProvider('queuedConfigurationChanges')]
    public function test_queued_work_rechecks_current_integration_and_tool_before_execution(string $change): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $schedule = $this->schedule();
        Queue::fake([RunQueuedReport::class]);
        Notification::fake();
        Http::preventStrayRequests();
        $run = app(DispatchScheduledReport::class)->handle($schedule, manual: true);
        if ($change === 'credentials') {
            $schedule->store->update(['shopify_access_token' => '']);
        } else {
            $schedule->update(['tool' => 'shipping_margin']);
        }
        Context::add('request-marker', 'keep-me');

        (new RunQueuedReport($run->id))->handle(app(RecordRun::class));

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($run->fresh()->result());
        $this->assertSame('failed', $schedule->fresh()->last_run_status);
        $this->assertSame('keep-me', Context::get('request-marker'));
        $this->assertNull(Context::get('report_schedule_id'));
        $this->assertDatabaseHas('run_logs', ['store_id' => $schedule->store_id, 'tool' => 'on_hold_stall', 'status' => 'error', 'scanned' => 0]);
        Http::assertNothingSent();
        Notification::assertNothingSent();
    }

    public static function queuedConfigurationChanges(): array
    {
        return ['revoked credentials' => ['credentials'], 'changed report tool' => ['tool']];
    }

    public function test_foreign_schedule_reference_cancels_run_without_overwriting_other_store_outcome(): void
    {
        $schedule = $this->schedule();
        $foreign = $this->schedule();
        $foreign->forceFill(['last_run_status' => 'completed'])->save();
        Queue::fake([RunQueuedReport::class]);
        $run = app(DispatchScheduledReport::class)->handle($schedule, manual: true);
        $run->forceFill(['report_schedule_id' => $foreign->id])->save();
        Http::preventStrayRequests();

        (new RunQueuedReport($run->id))->handle(app(RecordRun::class));

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame('completed', $foreign->fresh()->last_run_status);
        $this->assertNull($schedule->fresh()->last_run_status);
        $this->assertDatabaseCount('run_logs', 0);
        Http::assertNothingSent();
    }

    public function test_worker_timeout_marks_pending_run_failed_and_records_schedule_outcome_once(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $schedule = $this->schedule();
        Queue::fake([RunQueuedReport::class]);
        $run = app(DispatchScheduledReport::class)->handle($schedule, manual: true);
        $run->forceFill(['status' => 'running'])->save();
        $job = new RunQueuedReport($run->id);

        $job->failed(new RuntimeException('private-worker-token'));
        $this->travel(1)->hour();
        $job->failed(new RuntimeException('duplicate failure'));

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('2026-10-25 12:00:00', $run->fresh()->finished_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-25 12:00:00', $schedule->fresh()->last_finished_at->format('Y-m-d H:i:s'));
        $this->assertNull($schedule->fresh()->last_run_error);
        $this->assertDatabaseCount('run_logs', 0);
    }

    #[DataProvider('terminalStates')]
    public function test_late_worker_failure_and_duplicate_delivery_preserve_terminal_runs(string $status): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $schedule = $this->schedule();
        Queue::fake([RunQueuedReport::class]);
        $run = app(DispatchScheduledReport::class)->handle($schedule, manual: true);
        $run->storeResult(new ReportResult(rows: [['reference' => 'retained-result']], scanned: 1));
        $run->forceFill(['status' => $status])->save();
        $original = $run->getRawOriginal('result');
        $job = new RunQueuedReport($run->id);
        Http::preventStrayRequests();
        $this->travel(1)->hour();

        $job->failed(new RuntimeException('late-worker-error'));
        $job->handle(app(RecordRun::class));

        $this->assertSame($status, $run->fresh()->status);
        $this->assertSame($original, $run->fresh()->getRawOriginal('result'));
        $this->assertSame('2026-10-25 12:00:00', $run->fresh()->finished_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('run_logs', 0);
        Http::assertNothingSent();
    }

    public static function terminalStates(): array
    {
        return ['completed' => ['completed'], 'cancelled' => ['cancelled'], 'failed' => ['failed']];
    }

    public function test_deleted_report_run_is_a_no_op_for_worker_delivery_and_failure(): void
    {
        $job = new RunQueuedReport(123456);
        Http::preventStrayRequests();

        $job->handle(app(RecordRun::class));
        $job->failed(new RuntimeException('already pruned'));

        $this->assertDatabaseCount('report_runs', 0);
        $this->assertDatabaseCount('run_logs', 0);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $attributes */
    private function schedule(array $attributes = []): ReportSchedule
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);

        return ReportSchedule::factory()->for($store)->create(['user_id' => $admin->id, 'tool' => 'on_hold_stall', 'arguments' => ['2026-10-01', '2026-10-24'], 'parameters' => [], 'uses_date_range' => true, ...$attributes]);
    }
}
