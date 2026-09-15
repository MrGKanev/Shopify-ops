<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\PartialFulfillmentAnalyzer;
use PHPUnit\Framework\TestCase;

class PartialFulfillmentAnalyzerTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    public function test_it_uses_latest_progress_threshold_and_remaining_items(): void
    {
        $rows = (new PartialFulfillmentAnalyzer)->analyze([
            $this->order('#OLD', 20, [['created_at' => gmdate('c', self::NOW - 8 * 86400)]]),
            $this->order('#OLDER', 20),
            $this->order('#FAST', 2),
            $this->order('#DONE', 20, [], [['name' => 'Done', 'fulfillable_quantity' => 0]]),
        ], 7, self::NOW);

        $this->assertSame(['#OLDER', '#OLD'], array_column($rows, 'order_number'));
        $this->assertSame([20, 8], array_column($rows, 'days_stalled'));
        $this->assertSame([['name' => 'Widget', 'sku' => 'W-1', 'qty' => 2]], $rows[0]['unfulfilled_items']);
    }

    private function order(string $name, int $age, array $fulfillments = [], ?array $items = null): array
    {
        return ['id' => 1, 'name' => $name, 'created_at' => gmdate('c', self::NOW - $age * 86400), 'financial_status' => 'paid', 'fulfillments' => $fulfillments, 'line_items' => $items ?? [['name' => 'Widget', 'sku' => 'W-1', 'fulfillable_quantity' => 2]]];
    }
}
