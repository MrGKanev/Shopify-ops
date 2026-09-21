<?php

namespace App\Application\Reports;

use App\Domain\Reports\DisputeAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunDisputeReport extends RunScanReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly DisputeAnalyzer $analyzer) {}

    /** @return ScanResult<array{order_id: int|string, order_name: string, amount: float|string, currency: string, reason: string, status: string, initiated_at: string, days_until_due: int}> */
    public function handle(Store $store, int $now): ScanResult
    {
        $result = $this->shopify->openDisputes($store);

        return $this->scanResult($result, 'disputes', $this->analyzer->analyze($result['disputes'], $now));
    }
}
