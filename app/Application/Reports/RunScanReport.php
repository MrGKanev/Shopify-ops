<?php

namespace App\Application\Reports;

abstract class RunScanReport
{
    /**
     * @template TRow of array<string, mixed>
     *
     * @param  array<string, mixed>  $candidates
     * @param  list<TRow>  $rows
     * @param  array<string, bool|float|int|string|null>  $attributes
     * @return ReportResult<TRow>
     */
    protected function scanResult(array $candidates, string $itemsKey, array $rows, array $attributes = []): ReportResult
    {
        $metaKeys = ['skippedMissingCountry', 'totalVariants'];

        return new ReportResult(
            rows: $rows,
            scanned: count($candidates[$itemsKey] ?? []),
            pages: $candidates['pages'] ?? 0,
            truncated: $candidates['truncated'] ?? false,
            params: array_diff_key($attributes, array_flip($metaKeys)),
            meta: array_intersect_key($attributes, array_flip($metaKeys)),
        );
    }
}
