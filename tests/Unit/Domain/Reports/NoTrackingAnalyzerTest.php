<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\NoTrackingAnalyzer;
use PHPUnit\Framework\TestCase;

class NoTrackingAnalyzerTest extends TestCase
{
    public function test_it_filters_by_fulfillment_date_tracking_and_inclusive_grace_period(): void
    {
        $now = strtotime('2026-06-20T12:00:00Z');
        $orders = [['id' => 1, 'name' => '#1', 'created_at' => '2025-01-01', 'fulfillments' => [
            ['id' => 1, 'created_at' => '2026-06-18T12:00:00Z', 'tracking_number' => '', 'tracking_company' => 'UPS'],
            ['id' => 2, 'created_at' => '2026-06-19T12:00:00Z', 'tracking_number' => ''],
            ['id' => 3, 'created_at' => '2026-06-10T12:00:00Z', 'tracking_number' => ''],
            ['id' => 4, 'created_at' => '2026-06-18T12:00:00Z', 'tracking_number' => '1Z'],
        ]]];
        $rows = (new NoTrackingAnalyzer)->analyze($orders, '2026-06-15', '2026-06-20', 24, $now);
        $this->assertCount(1, $rows);
        $this->assertSame([48, 24], array_column($rows[0]['missing'], 'hours_ago'));
        $this->assertSame('UPS', $rows[0]['missing'][0]['company']);
    }
}
