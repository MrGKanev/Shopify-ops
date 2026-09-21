<?php

namespace App\Application\Reports;

readonly class NoteFlagResult extends DateRangeReportResult
{
    /** @param list<array<string, mixed>> $rows @param list<string> $keywords */
    public function __construct(
        string $startDate,
        string $endDate,
        public int $scanned,
        public array $rows,
        public array $keywords,
        public int $pages,
        public bool $truncated,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
