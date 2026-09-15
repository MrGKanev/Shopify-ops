<?php

namespace App\Application\Reports;

readonly class ActiveShipStationConflictResult
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(public string $startDate, public string $endDate, public int $scanned, public int $activeShipStation, public array $rows, public int $shopifyPages, public bool $shopifyTruncated) {}
}
