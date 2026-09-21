<?php

namespace App\Application\Reports;

abstract readonly class DateRangePaginatedRowsResult extends DateRangeReportResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        string $startDate,
        string $endDate,
        public array $rows,
        public int $pages,
        public bool $truncated,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
