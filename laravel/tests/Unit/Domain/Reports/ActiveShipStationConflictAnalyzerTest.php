<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ActiveShipStationConflictAnalyzer;
use PHPUnit\Framework\TestCase;

class ActiveShipStationConflictAnalyzerTest extends TestCase
{
    public function test_it_dedupes_exceptions_matches_active_orders_and_sorts(): void
    {
        $a = ['id' => 1, 'name' => '#1001', 'created_at' => '2026-06-01', 'financial_status' => 'refunded'];
        $b = ['id' => 2, 'name' => '#1002', 'created_at' => '2026-06-15', 'financial_status' => 'paid', 'cancelled_at' => '2026-06-16'];
        $normal = ['id' => 3, 'name' => '#1003', 'financial_status' => 'paid'];
        $ss = [['orderId' => 5, 'orderNumber' => '1001-B2', 'orderStatus' => 'on_hold'], ['orderId' => 6, 'orderNumber' => '1002', 'orderStatus' => 'awaiting_shipment']];
        $result = (new ActiveShipStationConflictAnalyzer)->analyze([$a, $a, $b, $normal], $ss);
        $this->assertSame(2, $result['scanned']);
        $this->assertSame(['#1002', '#1001'], array_column($result['rows'], 'order_number'));
        $this->assertSame(['cancelled', 'refunded'], array_column($result['rows'], 'issue'));
    }
}
