<?php

namespace App\Application\Reports;

use App\Models\Store;

class RunShopifyBacklogReport
{
    public function __construct(private readonly CaptureShopifyBacklog $capture) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store): ReportResult
    {
        $snapshot = $this->capture->handle($store);

        return new ReportResult(rows: $snapshot->result['rows'], scanned: $snapshot->rows_found, pages: $snapshot->result['pages'], truncated: false, meta: ['snapshot_id' => $snapshot->getKey(), 'counts' => array_diff_key($snapshot->result, ['rows' => true])]);
    }
}
