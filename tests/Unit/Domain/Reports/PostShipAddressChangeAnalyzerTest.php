<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\AddressChangeAnalyzer;
use PHPUnit\Framework\TestCase;

class PostShipAddressChangeAnalyzerTest extends TestCase
{
    public function test_it_keeps_changes_after_first_fulfillment_and_sorts_newest_first(): void
    {
        $orders = [
            '1' => $this->order('#NEW', '2026-06-02T10:00:00Z'),
            '2' => $this->order('#OLD', '2026-06-03T10:00:00Z'),
            '3' => $this->order('#BEFORE', '2026-06-05T10:00:00Z'),
        ];
        $rows = (new AddressChangeAnalyzer)->postShipRows($orders, ['1' => '2026-06-02T12:30:00Z', '2' => '2026-06-03T11:00:00Z', '3' => '2026-06-04T10:00:00Z']);

        $this->assertSame(['#OLD', '#NEW'], array_column($rows, 'order_number'));
        $this->assertSame(60, $rows[0]['mins_after_ship']);
        $this->assertSame('Jane Doe', $rows[0]['addr_name']);
        $this->assertSame('1 Main St, Boston, MA, 02101, US', $rows[0]['addr_line']);
    }

    private function order(string $name, string $fulfilledAt): array
    {
        return ['name' => $name, 'created_at' => '2026-06-01', 'fulfillments' => [['created_at' => $fulfilledAt], ['created_at' => '2026-06-10']], 'shipping_address' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '1 Main St', 'city' => 'Boston', 'province_code' => 'MA', 'zip' => '02101', 'country_code' => 'US']];
    }
}
