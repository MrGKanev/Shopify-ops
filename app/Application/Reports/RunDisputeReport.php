<?php

namespace App\Application\Reports;

use App\Domain\Reports\DisputeAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Models\Store;

class RunDisputeReport extends RunScanReport
{
    public function __construct(private readonly ShopifyPayments $shopify, private readonly DisputeAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, int $now): ReportResult
    {
        $result = $this->shopify->openDisputes($store);

        return $this->scanResult($result, 'disputes', $this->analyzer->analyze($result['disputes'], $now));
    }
}
