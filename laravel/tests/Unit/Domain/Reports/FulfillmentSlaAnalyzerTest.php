<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Orders\OrderTypeClassifier;
use App\Domain\Reports\FulfillmentSlaAnalyzer;
use Tests\TestCase;

class FulfillmentSlaAnalyzerTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    public function test_it_measures_open_and_fulfilled_orders_and_excludes_invalid_candidates(): void
    {
        $rows = (new FulfillmentSlaAnalyzer(new OrderTypeClassifier))->analyze([
            $this->order('#OPEN', 10),
            $this->order('#FULFILLED', 20, [['created_at' => gmdate('c', self::NOW - 15 * 86400)]]),
            $this->order('#FAST', 1),
            $this->order('#CANCELLED', 10, [], ['cancelled_at' => gmdate('c')]),
            $this->order('#REFUNDED', 10, [], ['financial_status' => 'refunded']),
        ], 3, self::NOW);

        $this->assertSame(['#OPEN', '#FULFILLED'], array_column($rows, 'order_number'));
        $this->assertSame([10, 5], array_column($rows, 'days'));
        $this->assertSame('Express', $rows[0]['method']);
        $this->assertSame('MA, US', $rows[0]['region']);
        $this->assertSame('Addons', $rows[0]['order_type']);
    }

    private function order(string $name, int $age, array $fulfillments = [], array $overrides = []): array
    {
        return $overrides + ['id' => 1, 'name' => $name, 'created_at' => gmdate('c', self::NOW - $age * 86400), 'cancelled_at' => null, 'financial_status' => 'paid', 'fulfillment_status' => null, 'fulfillments' => $fulfillments, 'shipping_lines' => [['title' => 'Express']], 'shipping_address' => ['province_code' => 'MA', 'country_code' => 'US']];
    }
}
