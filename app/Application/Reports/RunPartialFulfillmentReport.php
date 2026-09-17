<?php

namespace App\Application\Reports;

use App\Domain\Reports\PartialFulfillmentAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunPartialFulfillmentReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly PartialFulfillmentAnalyzer $analyzer) {}

    /** @return ScanResult<array{order_number: string, created_at: string, last_fulfilled: ?string, days_stalled: int, unfulfilled_items: list<array{name: string, sku: ?string, qty: int}>, email: string, total_price: float|string, financial: string}> */
    public function handle(Store $store, string $startDate, string $endDate, int $threshold): ScanResult
    {
        $data = $this->shopify->partialFulfillmentCandidates($store, $startDate, $endDate);

        return new ScanResult(scanned: count($data['orders']), rows: $this->analyzer->analyze($data['orders'], $threshold, time()), pages: $data['pages'], truncated: $data['truncated'], startDate: $startDate, endDate: $endDate, threshold: $threshold);
    }
}
