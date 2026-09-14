<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ShippedUnfulfilledAnalyzer;
use PHPUnit\Framework\TestCase;

class ShippedUnfulfilledAnalyzerTest extends TestCase
{
    public function test_it_counts_shipped_flags_only_unfulfilled_matches_and_sorts(): void
    {
        $ss = [['orderId' => 1, 'orderNumber' => '1001', 'orderStatus' => 'shipped', 'orderDate' => '2026-06-01'], ['orderId' => 2, 'orderNumber' => '1002', 'orderStatus' => 'shipped', 'orderDate' => '2026-06-15'], ['orderId' => 3, 'orderNumber' => '9999', 'orderStatus' => 'shipped'], ['orderId' => 4, 'orderNumber' => '1001', 'orderStatus' => 'awaiting_shipment']];
        $shopify = [['id' => 1, 'name' => '#1001', 'fulfillment_status' => 'fulfilled'], ['id' => 2, 'name' => '#1002', 'fulfillment_status' => 'partial']];
        $result = (new ShippedUnfulfilledAnalyzer)->analyze($ss, $shopify);
        $this->assertSame(3, $result['shipped_total']);
        $this->assertSame(['1002'], array_column($result['rows'], 'order_number'));
        $this->assertSame('partial', $result['rows'][0]['sh_fulfillment']);
    }
}
