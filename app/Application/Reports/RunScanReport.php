<?php

namespace App\Application\Reports;

abstract class RunScanReport
{
    /**
     * @param  array<string, mixed>  $candidates
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, bool|int|string|null>  $attributes
     */
    protected function scanResult(array $candidates, string $itemsKey, array $rows, array $attributes = []): ScanResult
    {
        return new ScanResult(...array_replace([
            'scanned' => count($candidates[$itemsKey] ?? []),
            'rows' => $rows,
            'pages' => $candidates['pages'] ?? null,
            'truncated' => $candidates['truncated'] ?? null,
        ], $attributes));
    }
}
