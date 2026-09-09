<?php

namespace App\Application\Reports;

readonly class OrphanOrderResult
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(public string $startDate, public string $endDate, public int $shipStationTotal, public int $shopifyTotal, public array $rows, public int $shopifyPages, public bool $shopifyTruncated) {}
}
