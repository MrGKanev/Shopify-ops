<?php

namespace App\Application\Reports;

readonly class VoidedShipmentsResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public string $startDate, public string $endDate, public array $rows) {}
}
