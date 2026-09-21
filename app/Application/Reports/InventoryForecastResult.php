<?php

namespace App\Application\Reports;

readonly class InventoryForecastResult extends InventoryDateRangeResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        string $startDate,
        string $endDate,
        int $products,
        int $variants,
        int $orders,
        array $rows,
        public int $critical,
        public int $warning,
        int $productPages,
        int $orderPages,
        bool $productsTruncated,
        bool $ordersTruncated,
    ) {
        parent::__construct($startDate, $endDate, $products, $variants, $orders, $rows, $productPages, $orderPages, $productsTruncated, $ordersTruncated);
    }
}
