<?php

namespace App\Application\Reports;

readonly class ShippedUnfulfilledResult extends DateRangeReportResult
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(
        string $startDate,
        string $endDate,
        public int $shippedTotal,
        public array $rows,
        public int $shopifyPages,
        public bool $shopifyTruncated,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
