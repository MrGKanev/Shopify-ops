<?php

namespace App\Application\Reports;

use App\Domain\Reports\FulfillmentSlaAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunFulfillmentSlaReport extends RunScanReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly FulfillmentSlaAnalyzer $analyzer) {}

    /** @return ReportResult<array{order_number: string, created_at: string, fulfilled_at: ?string, days: int, method: string, region: string, order_type: string, email: string, total: float|string, financial: string, fulfillment: string}> */
    public function handle(Store $store, string $startDate, string $endDate, int $threshold): ReportResult
    {
        $data = $this->shopify->fulfillmentSlaCandidates($store, $startDate, $endDate);

        return $this->scanResult($data, 'orders', $this->analyzer->analyze($data['orders'], $threshold, time()), ['startDate' => $startDate, 'endDate' => $endDate, 'threshold' => $threshold]);
    }
}
