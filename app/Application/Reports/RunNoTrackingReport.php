<?php

namespace App\Application\Reports;

use App\Domain\Reports\NoTrackingAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunNoTrackingReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly NoTrackingAnalyzer $analyzer) {}

    /** @return ScanResult<array{order_number: string, created_at: string, email: string, total: float|string, missing: list<array{created_at: string, hours_ago: int, company: string, status: string}>}> */
    public function handle(Store $store, string $startDate, string $endDate, int $threshold): ScanResult
    {
        $data = $this->shopify->noTrackingCandidates($store, $startDate);

        return new ScanResult(scanned: count($data['orders']), rows: $this->analyzer->analyze($data['orders'], $startDate, $endDate, $threshold, time()), pages: $data['pages'], truncated: $data['truncated'], startDate: $startDate, endDate: $endDate, threshold: $threshold);
    }
}
