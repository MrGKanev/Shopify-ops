<?php

namespace App\Application\Reports;

use App\Domain\Reports\FulfilledItemsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunFulfilledItemsReport extends RunScanReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly FulfilledItemsAnalyzer $analyzer) {}

    /** @return ReportResult<array{product: string, quantity: int}> */
    public function handle(Store $store, string $startDate, string $endDate): ReportResult
    {
        $candidates = $this->shopify->fulfilledItemCandidates($store, $startDate);

        return $this->scanResult($candidates, 'orders', $this->analyzer->analyze($candidates['orders'], $startDate, $endDate), ['startDate' => $startDate, 'endDate' => $endDate]);
    }
}
