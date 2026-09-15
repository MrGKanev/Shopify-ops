<?php

namespace App\Jobs;

use App\Application\Reports\RunAudit;
use App\Models\AuditJob;
use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Throwable;

class RunAuditJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $backoff = 30;

    public function __construct(public int $storeId, public string $startDate, public string $endDate, public ?int $auditJobId = null) {}

    public function handle(RunAudit $audit): void
    {
        $auditJob = $this->auditJobId === null ? null : AuditJob::find($this->auditJobId);
        $auditJob?->update(['status' => 'running', 'started_at' => now()]);
        Context::add(['audit_job_id' => $this->auditJobId, 'store_id' => $this->storeId, 'tool' => 'run_audit']);

        $audit->handle(Store::findOrFail($this->storeId), $this->startDate, $this->endDate);

        $auditJob?->update(['status' => 'completed', 'finished_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->auditJobId !== null) {
            AuditJob::whereKey($this->auditJobId)->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_category' => $exception === null ? 'unknown' : $exception::class,
            ]);
        }
    }

    public function uniqueId(): string
    {
        return "{$this->storeId}:{$this->startDate}:{$this->endDate}";
    }

    /** Safety-net lock TTL in case a worker crashes without releasing it. */
    public function uniqueFor(): int
    {
        return 3600;
    }
}
