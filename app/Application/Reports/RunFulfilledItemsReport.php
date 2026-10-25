<?php

namespace App\Application\Reports;

use App\Domain\Reports\FulfilledItemsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunFulfilledItemsReport extends RunScanReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly FulfilledItemsAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate): ReportResult
    {
        $candidates = $this->shopify->fulfilledItemCandidates($store, $startDate);

        return $this->scanResult($candidates, 'orders', $this->analyzer->analyze($candidates['orders'], $startDate, $endDate, $store->activeSkuAliases()), ['startDate' => $startDate, 'endDate' => $endDate, 'skuAliasesEnabled' => $store->activeSkuAliases() !== []]);
    }
}
