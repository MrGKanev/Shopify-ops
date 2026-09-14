<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\FulfilledItemsAnalyzer;
use PHPUnit\Framework\TestCase;

class FulfilledItemsAnalyzerTest extends TestCase
{
    public function test_it_counts_only_successful_fulfillments_in_range_and_sorts_products(): void
    {
        $orders = [['created_at' => '2025-01-01', 'fulfillments' => [
            $this->fulfillment('2026-07-15', 'success', [['title' => 'Zeta', 'variant_title' => 'Blue', 'quantity' => 2], ['title' => 'Alpha', 'variant_title' => 'Default Title', 'quantity' => 3]]),
            $this->fulfillment('2026-07-20', 'success', [['title' => 'Zeta', 'variant_title' => 'Blue', 'quantity' => 1]]),
            $this->fulfillment('2026-07-21', 'cancelled', [['title' => 'Hidden', 'quantity' => 99]]),
            $this->fulfillment('2026-08-01', 'success', [['title' => 'Hidden', 'quantity' => 99]]),
        ]]];

        $this->assertSame([['product' => 'Alpha', 'quantity' => 3], ['product' => 'Zeta Blue', 'quantity' => 3]], (new FulfilledItemsAnalyzer)->analyze($orders, '2026-07-01', '2026-07-31'));
    }

    /** @param list<array<string, mixed>> $items @return array<string, mixed> */
    private function fulfillment(string $date, string $status, array $items): array
    {
        return ['created_at' => $date.'T10:00:00Z', 'status' => $status, 'line_items' => $items];
    }
}
