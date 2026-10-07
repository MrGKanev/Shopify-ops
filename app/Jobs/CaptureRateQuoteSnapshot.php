<?php

namespace App\Jobs;

use App\Application\Orders\CaptureRateQuotes;
use App\Application\Reports\RecordRun;
use App\Domain\Orders\RateComparisonUnavailable;
use App\Models\RateQuoteSnapshot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Gate;
use Throwable;

class CaptureRateQuoteSnapshot implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    /** @var list<int> */
    public array $backoff = [10, 60, 180];

    public function __construct(public int $snapshotId) {}

    public function handle(CaptureRateQuotes $capture, RecordRun $runs): void
    {
        $snapshot = RateQuoteSnapshot::with(['store', 'user'])->findOrFail($this->snapshotId);
        if (! in_array($snapshot->status, ['queued', 'running'], true)) {
            return;
        }
        if ($snapshot->user === null || ! Gate::forUser($snapshot->user)->allows('run-audits') || ! $snapshot->user->stores()->whereKey($snapshot->store_id)->exists()) {
            $this->failed(new RateComparisonUnavailable('The operator no longer has access to this store.'));

            return;
        }
        $snapshot->update(['status' => 'running']);
        $started = microtime(true);
        try {
            $capture->handle($snapshot);
            $runs->handle($snapshot->store, ['tool' => 'rate_shopping', 'status' => 'ok', 'scanned' => count($snapshot->quoteRows()), 'rows_found' => count(array_filter($snapshot->quoteRows(), fn (array $quote): bool => $quote['eligible'])), 'duration_seconds' => round(microtime(true) - $started, 3)]);
        } catch (RateComparisonUnavailable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        RateQuoteSnapshot::whereKey($this->snapshotId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error_category' => $exception === null ? 'unknown' : $exception::class, 'message' => $exception instanceof RateComparisonUnavailable ? $exception->getMessage() : 'Rate quotes could not be retrieved. No comparison is available.']);
    }
}
