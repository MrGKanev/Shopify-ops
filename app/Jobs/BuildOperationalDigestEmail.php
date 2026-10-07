<?php

namespace App\Jobs;

use App\Application\Notifications\ReportNotifier;
use App\Models\ReportRun;
use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class BuildOperationalDigestEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /** @param list<array{tool: string, rows: int}> $sections */
    public function __construct(public int $storeId, public int $reportRunId, public array $sections = []) {}

    public function uniqueId(): string
    {
        return 'operational-digest:'.$this->reportRunId;
    }

    public function handle(ReportNotifier $notifier): void
    {
        $store = Store::find($this->storeId);
        $run = ReportRun::where('store_id', $this->storeId)->where('tool', 'operational_digest')->find($this->reportRunId);
        if ($store === null || $run === null || ! $run->isPending()) {
            return;
        }
        if (! $notifier->hasDigestRule($store, 'operational_digest')) {
            $run->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
            $notifier->emailDigest($store, $this->sections);

            return;
        }
        RunQueuedReport::dispatchSync($run->id);
        $result = $run->refresh()->result();
        $sections = $this->sections;
        if ($result !== null) {
            $sections[] = ['tool' => 'operational_digest', 'rows' => count($result->rows), 'summary' => $result->meta, 'snapshot_url' => route('saved-reports.show', $run->id)];
        }
        $notifier->emailDigest($store, $sections);
    }

    public function failed(?Throwable $exception): void
    {
        ReportRun::whereKey($this->reportRunId)->where('store_id', $this->storeId)->where('tool', 'operational_digest')->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'finished_at' => now()]);
    }
}
