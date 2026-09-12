<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\OrphanOrderAnalyzer;
use PHPUnit\Framework\TestCase;

class OrphanOrderAnalyzerTest extends TestCase
{
    public function test_it_matches_plain_compound_and_addon_numbers_and_sorts_real_orphans(): void
    {
        $ss = [$this->order('100042-B2', '2026-06-01'), $this->order('Addon-100031', '2026-06-02'), $this->order('9999', '2026-06-03'), $this->order('8888', '2026-06-15'), $this->order('', '2026-06-20')];
        $shopify = [['name' => '#100042'], ['order_number' => '100031']];
        $rows = (new OrphanOrderAnalyzer)->analyze($ss, $shopify);
        $this->assertSame(['8888', '9999'], array_column($rows, 'order_number'));
        $this->assertSame('Jane', $rows[0]['customer']);
    }

    private function order(string $number, string $date): array
    {
        return ['orderId' => 1, 'orderNumber' => $number, 'orderDate' => $date, 'orderStatus' => 'awaiting_shipment', 'shipTo' => ['name' => 'Jane'], 'orderTotal' => 10];
    }
}
