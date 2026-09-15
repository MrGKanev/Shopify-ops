<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\VoidedShipmentsAnalyzer;
use PHPUnit\Framework\TestCase;

class VoidedShipmentsAnalyzerTest extends TestCase
{
    public function test_it_builds_rows_tolerates_missing_address_and_sorts_by_void_date(): void
    {
        $rows = (new VoidedShipmentsAnalyzer)->analyze([
            ['orderNumber' => 'A', 'shipmentId' => 1, 'voidDate' => '2026-06-01T10:00:00Z', 'shipTo' => null],
            ['orderNumber' => 'B', 'shipmentId' => 2, 'trackingNumber' => '1Z', 'carrierCode' => 'ups', 'serviceCode' => 'ground', 'shipDate' => '2026-06-02T10:00:00Z', 'voidDate' => '2026-06-10T10:00:00Z', 'shipTo' => ['name' => 'Jane', 'city' => 'Boston', 'state' => 'MA', 'postalCode' => '02101', 'country' => 'US']],
        ]);

        $this->assertSame(['B', 'A'], array_column($rows, 'order_number'));
        $this->assertSame('2026-06-10', $rows[0]['void_date']);
        $this->assertSame('Jane', $rows[0]['ship_to_name']);
        $this->assertSame('', $rows[1]['ship_to_city']);
    }
}
