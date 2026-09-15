<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Orders\OrderChannelComparator;
use App\Domain\Orders\OrderTypeClassifier;
use App\Domain\Reports\ItemMismatchAnalyzer;
use Tests\TestCase;

class ItemMismatchAnalyzerTest extends TestCase
{
    public function test_it_diffs_shipped_items_applies_exclusions_and_prioritizes_required_gaps(): void
    {
        config(['order-types.fallback' => 'Other', 'order-types.rules' => [['name' => 'Widget', 'match' => 'sku_starts_with', 'value' => 'widget-', 'required_items' => [['label' => 'Spare', 'match' => 'sku_starts_with', 'value' => 'spare-']]]]]);
        $shopify = [['id' => 9, 'name' => '#1001', 'total_price' => 100, 'financial_status' => 'paid', 'line_items' => [['sku' => 'widget-a', 'quantity' => 1], ['sku' => 'spare-1', 'quantity' => 1]]], ['name' => '#1002', 'total_price' => 0, 'line_items' => [['sku' => 'x', 'quantity' => 1]]]];
        $ss = [['orderId' => 5, 'orderNumber' => '1001', 'orderStatus' => 'shipped', 'items' => [['sku' => 'widget-a', 'quantity' => 1]]], ['orderNumber' => '1002', 'orderStatus' => 'shipped', 'items' => []], ['orderNumber' => '1001', 'orderStatus' => 'awaiting_shipment', 'items' => []]];
        $rows = (new ItemMismatchAnalyzer(new OrderChannelComparator, new OrderTypeClassifier))->analyze($ss, $shopify);
        $this->assertCount(1, $rows);
        $this->assertSame(['spare-1' => 1], $rows[0]['missing']);
        $this->assertSame(['Widget: Spare'], $rows[0]['missing_required']);
        $this->assertSame('5', $rows[0]['ss_order_id']);
    }
}
