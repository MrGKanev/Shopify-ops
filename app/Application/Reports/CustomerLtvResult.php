<?php

namespace App\Application\Reports;

readonly class CustomerLtvResult extends DateRangeReportResult
{
    /** @param list<array<string, mixed>> $topCustomers @param list<array<string, mixed>> $cohorts */
    public function __construct(
        string $startDate,
        string $endDate,
        public int $scanned,
        public int $customers,
        public float $revenue,
        public array $topCustomers,
        public array $cohorts,
        public int $pages,
        public bool $truncated,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
