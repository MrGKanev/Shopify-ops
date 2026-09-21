<?php

namespace App\Application\Reports;

readonly class ShipmentAgingResult extends RowsResult
{
    /** @param list<array<string, mixed>> $rows @param list<array<string, mixed>> $bySku @param list<array<string, mixed>> $byType */
    public function __construct(
        public int $threshold,
        public int $scanned,
        array $rows,
        public array $bySku,
        public array $byType,
    ) {
        parent::__construct($rows);
    }
}
