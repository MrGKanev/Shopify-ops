<?php

namespace App\Application\Reports;

readonly class AddressCheckResult extends DateRangeReportResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        string $startDate,
        string $endDate,
        public int $scanned,
        public array $rows,
        public int $critical,
        public int $warnings,
        public bool $poBoxOnly,
        public bool $unfulfilledOnly,
        public int $pages,
        public bool $truncated,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
