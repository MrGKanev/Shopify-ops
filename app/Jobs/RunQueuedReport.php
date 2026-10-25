<?php

namespace App\Jobs;

use App\Application\Reports\RecordRun;
use App\Application\Reports\ScheduledReportPlan;
use App\Integrations\Exceptions\IntegrationException;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one report outside the web request, stores its result, and records the run
 * (which also sends the configured Slack, Discord and email notifications).
 */
class RunQueuedReport implements ShouldQueue
{
    use Queueable;

    /** Report failures are recorded, not retried: a retry would scan the APIs and notify again. */
    public int $tries = 1;

    /** Matches the Horizon supervisor timeout and stays below the queue retry_after (360 s). */
    public int $timeout = 300;

    public function __construct(public int $reportRunId) {}

    public function handle(RecordRun $runs): void
    {
        $run = ReportRun::query()->with('store')->find($this->reportRunId);
        $store = $run?->store;
        if ($run === null || $store === null || $run->status !== 'queued') {
            return;
        }

        $schedule = $run->report_schedule_id === null ? null : ReportSchedule::query()->with('user')->find($run->report_schedule_id);
        if ($run->report_schedule_id !== null && ($schedule === null || $schedule->store_id !== $run->store_id
            || ! ($schedule->user?->can('manage-administration') ?? false)
            || ! $schedule->user->stores()->whereKey($run->store_id)->exists()
            || ($run->scheduled_for !== null && ! $schedule->enabled))) {
            $run->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
            if ($schedule?->store_id === $run->store_id) {
                $schedule->recordOutcome($run);
            }

            return;
        }
        if (ReportRun::query()->whereKey($run->getKey())->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()]) !== 1) {
            return;
        }
        Context::scope(function () use ($run, $schedule, $runs): void {
            try {
                $this->execute($run, $schedule, $runs);
            } finally {
                $schedule?->recordOutcome($run->refresh());
            }
        }, ['report_run_id' => $run->id, 'report_schedule_id' => $schedule?->id, 'store_id' => $run->store_id, 'tool' => $run->tool]);
    }

    private function execute(ReportRun $run, ?ReportSchedule $schedule, RecordRun $runs): void
    {
        $store = $run->store ?? throw new \LogicException('The report store no longer exists.');
        $run->refresh();
        $started = microtime(true);
        $result = null;
        $actionStarted = false;

        try {
            if ($schedule !== null && app(ScheduledReportPlan::class)->action($schedule, $store) !== $run->report) {
                throw new \LogicException('The scheduled report action no longer matches its tool.');
            }
            $report = app($run->report);
            $actionStarted = true;
            $result = $report->handle($store, ...$run->arguments);
            $run->storeResult($result);
        } catch (Throwable $exception) {
            $result = null;
            $run->forceFill(['status' => 'failed', 'finished_at' => now(), 'failure_reason' => $exception instanceof IntegrationException ? $exception->userMessage() : null])->save();
            Log::warning('Queued report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof IntegrationException ? $exception->status : ($exception instanceof RequestException ? $exception->response->status() : null), 'store_id' => $run->store_id, 'tool' => $run->tool]);
        }

        if ($run->tool === 'run_audit' && $actionStarted) {
            return;
        }

        $runs->handle($store, [
            'tool' => $run->tool,
            'status' => $run->hasFailed() ? 'error' : 'ok',
            'start_date' => $run->start_date?->toDateString(),
            'end_date' => $run->end_date?->toDateString(),
            'duration_seconds' => round(microtime(true) - $started, 3),
            'scanned' => $result->scanned ?? 0,
            'rows_found' => $result === null ? 0 : count($result->rows),
        ]);
    }

    /**
     * A worker timeout or crash: make sure the page stops waiting.
     */
    public function failed(?Throwable $exception): void
    {
        if (ReportRun::query()->whereKey($this->reportRunId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'finished_at' => now()]) > 0) {
            $run = ReportRun::query()->find($this->reportRunId);
            if ($run !== null && $run->report_schedule_id !== null) {
                ReportSchedule::query()->where('store_id', $run->store_id)->find($run->report_schedule_id)?->recordOutcome($run);
            }
        }
    }
}
