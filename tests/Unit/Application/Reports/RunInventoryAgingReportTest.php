<?php

namespace Tests\Unit\Application\Reports;

use App\Application\Reports\ReportResult;
use App\Application\Reports\RunInventoryAgingReport;
use App\Domain\Reports\InventoryAgingAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyCatalog;
use App\Models\Store;
use Mockery;
use PHPUnit\Framework\TestCase;

class RunInventoryAgingReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_the_inventory_date_range_contract(): void
    {
        $store = new Store;
        $gateway = Mockery::mock(ShopifyCatalog::class);
        $gateway->shouldReceive('inventoryAgingCandidates')->once()->with($store, '2026-01-01', '2026-01-31')->andReturn([
            'products' => [],
            'orders' => [],
            'product_pages' => 2,
            'order_pages' => 3,
            'products_truncated' => true,
            'orders_truncated' => false,
        ]);

        $result = (new RunInventoryAgingReport($gateway, new InventoryAgingAnalyzer))->handle($store, '2026-01-01', '2026-01-31');

        $this->assertInstanceOf(ReportResult::class, $result);
        $this->assertSame(0, $result->meta['products']);
        $this->assertSame(0, $result->meta['variants']);
        $this->assertSame(0, $result->meta['orders']);
        $this->assertSame(2, $result->meta['productPages']);
        $this->assertSame(3, $result->meta['orderPages']);
        $this->assertTrue($result->meta['productsTruncated']);
        $this->assertFalse($result->meta['ordersTruncated']);
    }
}
