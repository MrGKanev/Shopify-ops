<?php

namespace App\Application\Reports;

abstract readonly class InventoryDateRangeResult extends DateRangeReportResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        string $startDate,
        string $endDate,
        public int $products,
        public int $variants,
        public int $orders,
        public array $rows,
        public int $productPages,
        public int $orderPages,
        public bool $productsTruncated,
        public bool $ordersTruncated,
    ) {
        parent::__construct($startDate, $endDate);
    }
}
