<?php

namespace App\Application\Reports;

readonly class RefundTrackerResult extends DateRangeReportResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        string $startDate,
        string $endDate,
        public int $scanned,
        public array $rows,
        public int $active,
        public int $missing,
        public bool $hasShipStation,
        public int $pages,
        public bool $truncated,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
