<?php

namespace App\Application\Reports;

readonly class PartialFulfillmentResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public string $startDate, public string $endDate, public int $threshold, public int $scanned, public array $rows, public int $pages, public bool $truncated) {}
}
