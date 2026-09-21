<?php

namespace Tests\Unit\Application\Reports;

use App\Application\Reports\AddressChangeResult;
use App\Application\Reports\RunAddressChangeReport;
use App\Domain\Reports\AddressChangeAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use Mockery;
use PHPUnit\Framework\TestCase;

class RunAddressChangeReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_the_date_range_paginated_rows_contract(): void
    {
        $store = new Store;
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('addressChangeCandidates')->once()->with($store, '2026-01-01', '2026-01-31')->andReturn([
            'orders' => ['1' => ['name' => '#1001', 'created_at' => '2026-01-01T10:00:00Z']],
            'events' => [['subject_id' => '1', 'message' => 'Shipping address was updated', 'created_at' => '2026-01-01T11:00:00Z']],
            'pages' => 2,
            'truncated' => true,
        ]);

        $result = (new RunAddressChangeReport($gateway, new AddressChangeAnalyzer))->handle($store, '2026-01-01', '2026-01-31');

        $this->assertInstanceOf(AddressChangeResult::class, $result);
        $this->assertSame('2026-01-01', $result->startDate);
        $this->assertSame('2026-01-31', $result->endDate);
        $this->assertSame(['#1001'], array_column($result->rows, 'order_number'));
        $this->assertSame(2, $result->pages);
        $this->assertTrue($result->truncated);
    }
}
