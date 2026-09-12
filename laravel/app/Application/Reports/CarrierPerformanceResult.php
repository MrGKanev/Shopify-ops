<?php

namespace App\Application\Reports;

readonly class CarrierPerformanceResult
{
    /** @param list<array{carrier: string, count: int, with_delivery: int, avg_days: float|null, late_count: int, late_pct: float|null}> $rows */
    public function __construct(public string $startDate, public string $endDate, public int $scanned, public array $rows) {}
}
