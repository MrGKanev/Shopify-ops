<?php

namespace App\Application\Reports;

use App\Domain\Reports\BundleCheckAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunBundleCheckReport extends RunScanReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly BundleCheckAnalyzer $analyzer) {}

    /** @return ScanResult<array{order_number: string, created_at: string, order_type: string, missing_text: string, fulfillment_status: string, financial_status: string, email: string, total: float|string}> */
    public function handle(Store $store, string $startDate, string $endDate): ScanResult
    {
        $data = $this->shopify->fulfillmentSlaCandidates($store, $startDate, $endDate);

        return $this->scanResult($data, 'orders', $this->analyzer->analyze($data['orders']), ['startDate' => $startDate, 'endDate' => $endDate]);
    }
}
