<?php

namespace App\Jobs;

use App\Application\Reports\RunAudit;
use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunAuditJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $storeId, public string $startDate, public string $endDate) {}

    public function handle(RunAudit $audit): void
    {
        $audit->handle(Store::findOrFail($this->storeId), $this->startDate, $this->endDate);
    }
}
