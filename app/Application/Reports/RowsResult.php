<?php

namespace App\Application\Reports;

abstract readonly class RowsResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public array $rows) {}
}
