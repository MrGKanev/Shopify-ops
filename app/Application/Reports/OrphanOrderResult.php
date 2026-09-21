<?php

namespace App\Application\Reports;

readonly class OrphanOrderResult extends DateRangeReportResult
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(
        string $startDate,
        string $endDate,
        public int $shipStationTotal,
        public int $shopifyTotal,
        public array $rows,
        public int $shopifyPages,
        public bool $shopifyTruncated,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
