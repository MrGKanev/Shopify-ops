<?php

namespace App\Application\Reports;

use App\Domain\Reports\ReturnedItemsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Models\Store;

class RunReturnedItemsReport extends RunScanReport
{
    public function __construct(private readonly ShopifyPayments $shopify, private readonly ReturnedItemsAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate): ReportResult
    {
        $candidates = $this->shopify->returnedItemCandidates($store, $startDate);

        return $this->scanResult($candidates, 'orders', $this->analyzer->analyze($candidates['orders'], $startDate, $endDate, $store->activeSkuAliases()), ['startDate' => $startDate, 'endDate' => $endDate, 'skuAliasesEnabled' => $store->activeSkuAliases() !== []]);
    }
}
