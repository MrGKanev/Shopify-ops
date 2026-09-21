<?php

namespace Tests\Unit\Application\Reports;

use App\Application\Reports\InventoryForecastResult;
use App\Application\Reports\RunInventoryForecastReport;
use App\Domain\Reports\InventoryForecastAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use Mockery;
use PHPUnit\Framework\TestCase;

class RunInventoryForecastReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_the_inventory_date_range_contract(): void
    {
        $store = new Store;
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('inventoryForecastCandidates')->once()->with($store, '2026-01-01', '2026-01-31')->andReturn([
            'products' => [],
            'orders' => [],
            'product_pages' => 2,
            'order_pages' => 3,
            'products_truncated' => true,
            'orders_truncated' => false,
        ]);

        $result = (new RunInventoryForecastReport($gateway, new InventoryForecastAnalyzer))->handle($store, '2026-01-01', '2026-01-31');

        $this->assertInstanceOf(InventoryForecastResult::class, $result);
        $this->assertSame(0, $result->critical);
        $this->assertSame(0, $result->warning);
        $this->assertSame(2, $result->productPages);
        $this->assertSame(3, $result->orderPages);
        $this->assertTrue($result->productsTruncated);
        $this->assertFalse($result->ordersTruncated);
    }
}
