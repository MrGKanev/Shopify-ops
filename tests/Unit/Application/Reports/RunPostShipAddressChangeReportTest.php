<?php

namespace Tests\Unit\Application\Reports;

use App\Application\Reports\PostShipAddressChangeResult;
use App\Application\Reports\RunPostShipAddressChangeReport;
use App\Domain\Reports\AddressChangeAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use Mockery;
use PHPUnit\Framework\TestCase;

class RunPostShipAddressChangeReportTest extends TestCase
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
            'orders' => [],
            'events' => [],
            'pages' => 4,
            'truncated' => true,
        ]);

        $result = (new RunPostShipAddressChangeReport($gateway, new AddressChangeAnalyzer))->handle($store, '2026-01-01', '2026-01-31');

        $this->assertInstanceOf(PostShipAddressChangeResult::class, $result);
        $this->assertSame('2026-01-01', $result->startDate);
        $this->assertSame('2026-01-31', $result->endDate);
        $this->assertSame([], $result->rows);
        $this->assertSame(4, $result->pages);
        $this->assertTrue($result->truncated);
    }
}
