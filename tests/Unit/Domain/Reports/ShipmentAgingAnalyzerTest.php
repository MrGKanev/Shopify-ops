<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Orders\OrderTypeClassifier;
use App\Domain\Reports\ShipmentAgingAnalyzer;
use Tests\TestCase;

class ShipmentAgingAnalyzerTest extends TestCase
{
    public function test_it_applies_inclusive_age_and_builds_sku_and_type_summaries(): void
    {
        $now = 1_800_000_000;
        config(['order-types.fallback' => 'Other', 'order-types.rules' => [['name' => 'Widgets', 'match' => 'sku_starts_with', 'value' => 'SKU-']]]);
        $orders = [
            $this->order(1, 10, [['sku' => 'SKU-A', 'name' => 'Widget', 'quantity' => 2], ['sku' => '', 'name' => 'Blank', 'quantity' => 1]], $now),
            $this->order(2, 3, [['sku' => 'SKU-A', 'name' => 'Widget', 'quantity' => 3]], $now),
            $this->order(3, 2, [], $now),
            ['orderId' => 4, 'orderDate' => 'bad'],
        ];
        $result = (new ShipmentAgingAnalyzer(new OrderTypeClassifier))->analyze($orders, 3, $now);
        $this->assertSame(['1', '2'], array_column($result['rows'], 'ss_order_id'));
        $this->assertSame(['sku' => 'SKU-A', 'orders' => 2, 'qty' => 5, 'oldest_days' => 10], $result['by_sku'][0]);
        $this->assertSame(['type' => 'Widgets', 'orders' => 2, 'oldest_days' => 10], $result['by_type'][0]);
        $this->assertSame(['SKU-A' => 2], $result['rows'][0]['skus']);
    }

    private function order(int $id, int $age, array $items, int $now): array
    {
        return ['orderId' => $id, 'orderNumber' => (string) $id, 'orderDate' => gmdate('c', $now - $age * 86400), 'orderStatus' => 'awaiting_shipment', 'items' => $items];
    }
}
