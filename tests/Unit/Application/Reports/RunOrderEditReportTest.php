<?php

namespace Tests\Unit\Application\Reports;

use App\Application\Reports\ReportResult;
use App\Application\Reports\RunOrderEditReport;
use App\Domain\Reports\OrderEditAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Models\Store;
use Mockery;
use PHPUnit\Framework\TestCase;

class RunOrderEditReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_the_date_range_paginated_rows_contract(): void
    {
        $store = new Store;
        $gateway = Mockery::mock(ShopifyOrders::class);
        $gateway->shouldReceive('orderEditCandidates')->once()->with($store, '2026-01-01', '2026-01-31')->andReturn([
            'orders' => [],
            'events' => [],
            'pages' => 3,
            'truncated' => false,
        ]);

        $result = (new RunOrderEditReport($gateway, new OrderEditAnalyzer(new ShopifyOrderEventNormalizer)))->handle($store, '2026-01-01', '2026-01-31');

        $this->assertInstanceOf(ReportResult::class, $result);
        $this->assertSame('2026-01-01', $result->params['startDate']);
        $this->assertSame('2026-01-31', $result->params['endDate']);
        $this->assertSame([], $result->rows);
        $this->assertSame(3, $result->pages);
        $this->assertFalse($result->truncated);
    }
}
