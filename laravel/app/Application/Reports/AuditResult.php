<?php

namespace App\Application\Reports;

readonly class AuditResult
{
    /** @param list<array<string,mixed>> $missing */
    public function __construct(public string $startDate, public string $endDate, public array $missing, public int $found, public int $skipped, public int $ignored, public int $shopifyTotal, public int $shipstationTotal, public bool $shopifyTruncated) {}
}
