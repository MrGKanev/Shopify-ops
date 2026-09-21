<?php

namespace App\Application\Reports;

readonly class TagAuditResult extends DateRangeReportResult
{
    /** @param list<array{tag: string, count: int, last_order: string, last_date: string, orphan: bool}> $tags */
    public function __construct(
        string $startDate,
        string $endDate,
        public int $scanned,
        public array $tags,
        public int $pages,
        public bool $truncated,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
