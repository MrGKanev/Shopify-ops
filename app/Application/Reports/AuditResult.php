<?php

namespace App\Application\Reports;

readonly class AuditResult
{
    /**
     * @param  list<array<string,mixed>>  $missing
     * @param  list<array{email: string, amount: string, orders: list<array<string, mixed>>}>  $duplicates
     */
    public function __construct(public string $startDate, public string $endDate, public array $missing, public int $found, public int $skipped, public int $ignored, public int $shopifyTotal, public int $shipstationTotal, public bool $shopifyTruncated, public array $duplicates = []) {}
}
