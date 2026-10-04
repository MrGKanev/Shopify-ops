<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Application\Reports\RunCatalogQualityReport;
use App\Application\Reports\RunConsentAuditReport;
use App\Application\Reports\RunFraudRiskReport;
use App\Application\Reports\RunTaxAuditReport;
use App\Integrations\Shopify\Contracts\ShopifyCatalog;
use App\Integrations\Shopify\Contracts\ShopifyCustomers;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Models\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShopifyCapabilitiesTest extends TestCase
{
    #[DataProvider('capabilities')]
    public function test_reports_run_with_only_the_capability_they_need(string $contract, string $method, string $report, array $arguments, string $itemsKey): void
    {
        $store = new Store;
        $gateway = $this->mock($contract);
        $gateway->shouldReceive($method)->once()->with($store, ...array_slice($arguments, 0, 2))->andReturn([$itemsKey => [], 'pages' => 2, 'truncated' => true]);

        $result = app($report)->handle($store, ...$arguments);

        $this->assertSame([], $result->rows);
        $this->assertSame(0, $result->scanned);
        $this->assertSame(2, $result->pages);
        $this->assertTrue($result->truncated);
    }

    public static function capabilities(): array
    {
        return [
            'orders' => [ShopifyOrders::class, 'fraudRiskCandidates', RunFraudRiskReport::class, ['2026-06-01', '2026-06-30'], 'orders'],
            'catalog' => [ShopifyCatalog::class, 'catalogQualityCandidates', RunCatalogQualityReport::class, [], 'products'],
            'customers' => [ShopifyCustomers::class, 'consentAuditCandidates', RunConsentAuditReport::class, ['2026-06-01', '2026-06-30'], 'orders'],
            'payments' => [ShopifyPayments::class, 'taxAuditCandidates', RunTaxAuditReport::class, ['2026-06-01', '2026-06-30', 1.0], 'orders'],
        ];
    }

    public function test_replacing_one_capability_does_not_replace_the_others(): void
    {
        $orders = $this->mock(ShopifyOrders::class);
        $catalog = $this->mock(ShopifyCatalog::class);

        $this->assertSame($orders, app(ShopifyOrders::class));
        $this->assertSame($catalog, app(ShopifyCatalog::class));
        $this->assertNotSame($orders, app(ShopifyPayments::class));
        $this->assertNotSame($catalog, app(ShopifyCustomers::class));
    }
}
