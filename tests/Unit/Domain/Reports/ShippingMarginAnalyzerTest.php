<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ShippingMarginAnalyzer;
use PHPUnit\Framework\TestCase;

class ShippingMarginAnalyzerTest extends TestCase
{
    public function test_it_matches_orders_filters_losses_and_builds_carrier_summary(): void
    {
        $analyzer = new ShippingMarginAnalyzer;
        $rows = $analyzer->analyze([
            $this->shipment('6001', 40, 0, 'fedex'),
            $this->shipment('6002', 12, 0, 'ups'),
            $this->shipment('6001', 100, 0, 'fedex', true),
            $this->shipment('9999', 100, 0, 'ups'),
        ], [
            ['id' => 1, 'order_number' => '6001', 'email' => 'a@example.com', 'total_price' => '99.00', 'shipping_lines' => [['price' => '5.00'], ['price' => '5.00']]],
            ['id' => 2, 'order_number' => '6002', 'shipping_lines' => [['price' => '10.00']]],
        ], 15);

        $this->assertCount(1, $rows);
        $this->assertSame(40.0, $rows[0]['ship_cost']);
        $this->assertSame(10.0, $rows[0]['shipping_charged']);
        $this->assertSame(30.0, $rows[0]['loss']);
        $this->assertSame([['carrier' => 'fedex', 'count' => 1, 'total_loss' => 30.0, 'avg_loss' => 30.0]], $analyzer->byCarrier($rows));
    }

    private function shipment(string $number, float $cost, float $insurance, string $carrier, bool $voided = false): array
    {
        return ['orderId' => 1, 'orderNumber' => $number, 'shipDate' => '2026-06-10T00:00:00Z', 'carrierCode' => $carrier, 'serviceCode' => 'ground', 'shipmentCost' => $cost, 'insuranceCost' => $insurance, 'voided' => $voided];
    }
}
