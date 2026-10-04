<?php

namespace App\Application\Reports;

use App\Domain\Reports\NoTrackingAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunNoTrackingReport extends RunScanReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly NoTrackingAnalyzer $analyzer) {}

    /** @return ReportResult<array{order_number: string, created_at: string, email: string, total: float|string, missing: list<array{created_at: string, hours_ago: int, company: string, status: string}>}> */
    public function handle(Store $store, string $startDate, string $endDate, int $threshold): ReportResult
    {
        $data = $this->shopify->noTrackingCandidates($store, $startDate);

        return $this->scanResult($data, 'orders', $this->analyzer->analyze($data['orders'], $startDate, $endDate, $threshold, time()), ['startDate' => $startDate, 'endDate' => $endDate, 'threshold' => $threshold]);
    }
}
