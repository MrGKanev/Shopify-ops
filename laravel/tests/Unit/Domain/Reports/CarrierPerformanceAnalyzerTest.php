<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\CarrierPerformanceAnalyzer;
use PHPUnit\Framework\TestCase;

class CarrierPerformanceAnalyzerTest extends TestCase
{
    public function test_it_groups_delivery_performance_and_excludes_missing_or_bad_dates(): void
    {
        $rows = (new CarrierPerformanceAnalyzer)->analyze([
            $this->shipment('ups', '2026-06-01', '2026-06-03'),
            $this->shipment('ups', '2026-06-01', '2026-06-07'),
            $this->shipment('ups', '2026-06-01', ''),
            $this->shipment('fedex', '2026-06-06', '2026-06-01'),
            $this->shipment('', '2026-06-01', '2026-06-06'),
        ]);

        $this->assertSame(['carrier' => 'ups', 'count' => 3, 'with_delivery' => 2, 'avg_days' => 4.0, 'late_count' => 1, 'late_pct' => 50.0], $rows[0]);
        $this->assertSame(['carrier' => 'Unknown', 'count' => 1, 'with_delivery' => 1, 'avg_days' => 5.0, 'late_count' => 0, 'late_pct' => 0.0], $rows[1]);
        $this->assertSame(['carrier' => 'fedex', 'count' => 1, 'with_delivery' => 0, 'avg_days' => null, 'late_count' => 0, 'late_pct' => null], $rows[2]);
    }

    private function shipment(string $carrier, string $shipped, string $delivered): array
    {
        return ['carrierCode' => $carrier, 'shipDate' => $shipped.'T00:00:00Z', 'deliveryDate' => $delivered === '' ? '' : $delivered.'T00:00:00Z'];
    }
}
