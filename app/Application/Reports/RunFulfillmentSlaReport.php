<?php

namespace App\Application\Reports;

use App\Domain\Reports\FulfillmentSlaAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunFulfillmentSlaReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly FulfillmentSlaAnalyzer $analyzer) {}

    /** @return ScanResult<array{order_number: string, created_at: string, fulfilled_at: ?string, days: int, method: string, region: string, order_type: string, email: string, total: float|string, financial: string, fulfillment: string}> */
    public function handle(Store $store, string $startDate, string $endDate, int $threshold): ScanResult
    {
        $data = $this->shopify->fulfillmentSlaCandidates($store, $startDate, $endDate);

        return new ScanResult(scanned: count($data['orders']), rows: $this->analyzer->analyze($data['orders'], $threshold, time()), pages: $data['pages'], truncated: $data['truncated'], startDate: $startDate, endDate: $endDate, threshold: $threshold);
    }
}
