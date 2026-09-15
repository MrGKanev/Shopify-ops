<?php

namespace App\Application\Reports;

readonly class ShippingMarginResult
{
    /** @param list<array<string, mixed>> $rows @param list<array{carrier: string, count: int, total_loss: float, avg_loss: float}> $byCarrier */
    public function __construct(public string $startDate, public string $endDate, public float $threshold, public int $scanned, public array $rows, public array $byCarrier, public int $shopifyPages, public bool $shopifyTruncated) {}
}
