<?php

namespace App\Application\Reports;

use App\Domain\Reports\OnHoldStallAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunOnHoldStallReport extends RunScanReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly OnHoldStallAnalyzer $analyzer) {}

    /** @return ScanResult<array{order_number: string, created_at: string, days_waiting: int, hold_reason: string, hold_notes: string, email: string, total: float|string, financial: string, fulfillment: string}> */
    public function handle(Store $store, string $startDate, string $endDate): ScanResult
    {
        $data = $this->shopify->onHoldFulfillmentCandidates($store, $startDate, $endDate);

        return $this->scanResult($data, 'fulfillment_orders', $this->analyzer->analyze($data['fulfillment_orders'], time()), ['startDate' => $startDate, 'endDate' => $endDate]);
    }
}
