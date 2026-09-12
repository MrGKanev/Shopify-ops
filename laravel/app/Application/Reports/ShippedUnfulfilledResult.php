<?php

namespace App\Application\Reports;

readonly class ShippedUnfulfilledResult
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(public string $startDate, public string $endDate, public int $shippedTotal, public array $rows, public int $shopifyPages, public bool $shopifyTruncated) {}
}
