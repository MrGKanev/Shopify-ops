<?php

namespace App\Jobs;

use App\Application\Reports\RecordRun;
use App\Models\ReportRun;
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
        if ($run === null || ! $run->isPending()) {
            return;
        }

        Context::add(['report_run_id' => $run->getKey(), 'store_id' => $run->store_id, 'tool' => $run->tool]);
        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();
        $started = microtime(true);
        $result = null;

        try {
            $result = app($run->report)->handle($run->store, ...$run->arguments);
            $run->storeResult($result);
        } catch (Throwable $exception) {
            $run->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
            Log::warning('Queued report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $run->store_id, 'tool' => $run->tool]);
        }

        $runs->handle($run->store, [
            'tool' => $run->tool,
            'status' => $run->hasFailed() ? 'error' : 'ok',
            'start_date' => $run->start_date?->toDateString(),
            'end_date' => $run->end_date?->toDateString(),
            'duration_seconds' => round(microtime(true) - $started, 3),
            'scanned' => $run->metric($result, $run->scanned_metric),
            'rows_found' => $run->metric($result, $run->rows_metric),
        ]);
    }

    /**
     * A worker timeout or crash: make sure the page stops waiting.
     */
    public function failed(?Throwable $exception): void
    {
        ReportRun::query()->whereKey($this->reportRunId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'finished_at' => now()]);
    }
}
