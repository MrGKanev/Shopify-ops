<?php

namespace Tests\Unit;

use App\Domain\Reports\AuditOrderAnalyzer;
use PHPUnit\Framework\TestCase;

class AuditOrderAnalyzerTest extends TestCase
{
    public function test_it_classifies_ignored_skipped_number_email_and_missing_orders(): void
    {
        $orders = [
            ['name' => '#1001', 'financial_status' => 'paid', 'total_price' => 10],
            ['name' => '#1002', 'email' => 'buyer@example.com', 'financial_status' => 'paid', 'total_price' => 100],
            ['name' => '#1003', 'financial_status' => 'refunded', 'total_price' => 10],
            ['name' => '#1004', 'financial_status' => 'paid', 'total_price' => 10],
            ['name' => '#1005', 'financial_status' => 'paid', 'total_price' => 10],
            ['id' => 6, 'name' => '#1006', 'financial_status' => 'paid', 'total_price' => 10, 'shipping_lines' => []],
            ['id' => 7, 'name' => '#1007', 'financial_status' => 'paid', 'total_price' => 10, 'shipping_lines' => [['id' => 1]]],
        ];
        $shipstation = [
            ['orderNumber' => '1001', 'orderTotal' => 10],
            ['orderNumber' => 'other', 'customerEmail' => 'BUYER@example.com', 'orderTotal' => 101],
        ];

        $result = (new AuditOrderAnalyzer)->analyze($orders, $shipstation, ['1004' => ['reason' => 'known']], ['7' => true]);

        $this->assertCount(1, $result['found']);
        $this->assertSame('order_number', $result['found'][0]['match_method']);
        $this->assertSame(['financial', 'no_shipping', 'on_hold'], array_column($result['skipped'], 'skip_reason'));
        $this->assertCount(1, $result['ignored']);
        $this->assertSame(['#1002', '#1005'], array_column($result['missing'], 'name'));
    }
}
