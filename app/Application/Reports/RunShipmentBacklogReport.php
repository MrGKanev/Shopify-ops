<?php

namespace App\Application\Reports;

use App\Models\Store;

class RunShipmentBacklogReport
{
    public function __construct(private readonly CaptureShipmentBacklog $capture) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store): ReportResult
    {
        $snapshot = $this->capture->handle($store);

        return new ReportResult(rows: [], scanned: $snapshot->rows_found, pages: 0, truncated: false, params: [], meta: ['snapshot_id' => $snapshot->getKey(), 'counts' => $snapshot->result]);
    }
}
