<?php

namespace App\Application\Reports;

readonly class DuplicateOrderResult
{
    /** @param list<array{first: array<string, mixed>, second: array<string, mixed>, gap_seconds: int}> $pairs */
    public function __construct(public string $startDate, public string $endDate, public int $scanned, public array $pairs, public int $pages, public bool $truncated) {}
}
