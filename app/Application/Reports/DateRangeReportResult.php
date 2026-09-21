<?php

namespace App\Application\Reports;

abstract readonly class DateRangeReportResult
{
    public function __construct(
        public string $startDate,
        public string $endDate,
    ) {}
}
