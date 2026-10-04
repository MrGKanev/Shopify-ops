<?php

namespace App\Application\Reports;

use App\Domain\Reports\InventoryForecastAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyCatalog;
use App\Models\Store;

class RunInventoryForecastReport
{
    public function __construct(private readonly ShopifyCatalog $shopify, private readonly InventoryForecastAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate): ReportResult
    {
        $candidates = $this->shopify->inventoryForecastCandidates($store, $startDate, $endDate);
        $analysis = $this->analyzer->analyze($candidates['products'], $candidates['orders']);

        return new ReportResult(rows: $analysis['rows'], scanned: count($candidates['orders']), pages: $candidates['product_pages'], truncated: ($candidates['products_truncated']) || ($candidates['orders_truncated']), params: ['startDate' => $startDate, 'endDate' => $endDate], meta: ['products' => count($candidates['products']), 'variants' => $analysis['variants'], 'orders' => count($candidates['orders']), 'critical' => $analysis['critical'], 'warning' => $analysis['warning'], 'productPages' => $candidates['product_pages'], 'orderPages' => $candidates['order_pages'], 'productsTruncated' => $candidates['products_truncated'], 'ordersTruncated' => $candidates['orders_truncated']]);
    }
}
