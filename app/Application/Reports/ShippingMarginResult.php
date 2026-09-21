<?php

namespace App\Application\Reports;

readonly class ShippingMarginResult extends DateRangeReportResult
{
    /** @param list<array<string, mixed>> $rows @param list<array{carrier: string, count: int, total_loss: float, avg_loss: float}> $byCarrier */
    public function __construct(
        string $startDate,
        string $endDate,
        public float $threshold,
        public int $scanned,
        public array $rows,
        public array $byCarrier,
        public int $shopifyPages,
        public bool $shopifyTruncated,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
