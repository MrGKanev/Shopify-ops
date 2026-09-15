<?php

namespace App\Application\Reports;

readonly class CustomerLtvResult
{
    /** @param list<array<string, mixed>> $topCustomers @param list<array<string, mixed>> $cohorts */
    public function __construct(public string $startDate, public string $endDate, public int $scanned, public int $customers, public float $revenue, public array $topCustomers, public array $cohorts, public int $pages, public bool $truncated) {}
}
