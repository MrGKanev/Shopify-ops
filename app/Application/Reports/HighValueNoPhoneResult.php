<?php

namespace App\Application\Reports;

readonly class HighValueNoPhoneResult extends DateRangeReportResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        string $startDate,
        string $endDate,
        public float $minimum,
        public ?string $currency,
        public int $scanned,
        public array $rows,
        public int $pages,
        public bool $truncated,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
