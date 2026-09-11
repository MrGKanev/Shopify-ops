<?php

namespace App\Jobs;

use App\Application\Reports\RunAudit;
use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunAuditJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $backoff = 30;

    public function __construct(public int $storeId, public string $startDate, public string $endDate) {}

    public function handle(RunAudit $audit): void
    {
        $audit->handle(Store::findOrFail($this->storeId), $this->startDate, $this->endDate);
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
