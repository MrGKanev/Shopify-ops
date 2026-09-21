<?php

namespace App\Application\Reports;

readonly class DuplicateOrderResult extends DateRangeReportResult
{
    /** @param list<array{first: array<string, mixed>, second: array<string, mixed>, gap_seconds: int}> $pairs */
    public function __construct(
        string $startDate,
        string $endDate,
        public int $scanned,
        public array $pairs,
        public int $pages,
        public bool $truncated,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
