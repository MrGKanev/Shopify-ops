<?php

namespace App\Application\Reports;

use App\Domain\Reports\ReturnRmaAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Models\Store;

class RunReturnRmaReport
{
    public function __construct(private readonly ShopifyPayments $shopify, private readonly ReturnRmaAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate): ReportResult
    {
        $candidates = $this->shopify->refundTrackerCandidates($store, $startDate, $endDate);
        $analysis = $this->analyzer->analyze($candidates['orders']);

        return new ReportResult(rows: $analysis['rows'], scanned: count($candidates['orders']), pages: $candidates['pages'], truncated: $candidates['truncated'], params: ['startDate' => $startDate, 'endDate' => $endDate], meta: ['skuStats' => $analysis['sku_stats']]);
    }
}
