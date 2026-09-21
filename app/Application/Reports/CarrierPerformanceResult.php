<?php

namespace App\Application\Reports;

readonly class CarrierPerformanceResult extends DateRangeReportResult
{
    /** @param list<array{carrier: string, count: int, with_delivery: int, avg_days: float|null, late_count: int, late_pct: float|null}> $rows */
    public function __construct(
        string $startDate,
        string $endDate,
        public int $scanned,
        public array $rows,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
