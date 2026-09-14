<?php

namespace App\Http\Controllers\Concerns;

use App\Application\Reports\RecordRun;
use App\Models\Store;

trait RecordsReportRun
{
    private function recordReportRun(RecordRun $runs, Store $store, string $tool, float $startedAt, ?string $startDate, ?string $endDate, int $scanned, int $rowsFound, bool $failed): void
    {
        $runs->handle($store, [
            'tool' => $tool,
            'status' => $failed ? 'error' : 'ok',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
            'scanned' => $scanned,
            'rows_found' => $rowsFound,
        ]);
    }
}
