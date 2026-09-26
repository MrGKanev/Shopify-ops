<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\DeliveryExceptionAnalyzer;
use PHPUnit\Framework\TestCase;

class DeliveryExceptionAnalyzerTest extends TestCase
{
    public function test_flags_old_shipments_without_confirmation_and_marks_delivered_or_voided_shipments_resolved(): void
    {
        $now = strtotime('2026-09-26T12:00:00Z');
        $shipments = [
            ['shipmentId' => 1, 'orderNumber' => '#1001', 'trackingNumber' => 'TRACK-1', 'shipDate' => '2026-09-19T12:00:00Z', 'carrierCode' => 'ups'],
            ['shipmentId' => 2, 'orderNumber' => '#1002', 'trackingNumber' => 'TRACK-2', 'shipDate' => '2026-09-19T12:00:00Z', 'deliveryDate' => '2026-09-25T12:00:00Z'],
            ['shipmentId' => 3, 'orderNumber' => '#1003', 'trackingNumber' => 'TRACK-3', 'shipDate' => '2026-09-19T12:00:00Z', 'voidDate' => '2026-09-25T12:00:00Z'],
            ['shipmentId' => 4, 'orderNumber' => '#1004', 'trackingNumber' => 'TRACK-4', 'shipDate' => '2026-09-24T12:00:00Z'],
            ['shipmentId' => '', 'orderNumber' => '', 'trackingNumber' => '', 'shipDate' => '2026-09-19T12:00:00Z'],
        ];

        $rows = (new DeliveryExceptionAnalyzer)->analyze($shipments, 5, $now);

        $this->assertSame(['#1001', '#1002', '#1003'], array_column($rows, 'reference'));
        $this->assertSame('normal', $rows[0]['priority']);
        $this->assertSame(7, $rows[0]['days']);
        $this->assertSame(false, $rows[0]['resolved']);
        $this->assertSame(true, $rows[1]['resolved']);
        $this->assertSame(true, $rows[2]['resolved']);
    }
}
