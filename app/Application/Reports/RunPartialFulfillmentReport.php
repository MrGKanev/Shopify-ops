<?php

namespace App\Application\Reports;

use App\Domain\Reports\PartialFulfillmentAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunPartialFulfillmentReport extends RunScanReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly PartialFulfillmentAnalyzer $analyzer) {}

    /** @return ReportResult<array{order_number: string, created_at: string, last_fulfilled: ?string, days_stalled: int, unfulfilled_items: list<array{name: string, sku: ?string, qty: int}>, email: string, total_price: float|string, financial: string}> */
    public function handle(Store $store, string $startDate, string $endDate, int $threshold): ReportResult
    {
        $data = $this->shopify->partialFulfillmentCandidates($store, $startDate, $endDate);

        return $this->scanResult($data, 'orders', $this->analyzer->analyze($data['orders'], $threshold, time()), ['startDate' => $startDate, 'endDate' => $endDate, 'threshold' => $threshold]);
    }
}
