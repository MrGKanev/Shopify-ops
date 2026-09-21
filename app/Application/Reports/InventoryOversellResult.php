<?php

namespace App\Application\Reports;

readonly class InventoryOversellResult extends RowsResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public int $products,
        public int $awaitingOrders,
        array $rows,
        public int $productPages,
        public bool $productsTruncated,
    ) {
        parent::__construct($rows);
    }
}
