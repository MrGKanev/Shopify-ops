<?php

namespace App\Application\Reports;

readonly class VoidedShipmentsResult extends DateRangeReportResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        string $startDate,
        string $endDate,
        public array $rows,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
