<?php

namespace App\Application\Reports;

use App\Domain\Reports\OnHoldStallAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunOnHoldStallReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly OnHoldStallAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate): OnHoldStallResult
    {
        $data = $this->shopify->onHoldFulfillmentCandidates($store, $startDate, $endDate);

        return new OnHoldStallResult($startDate, $endDate, count($data['fulfillment_orders']), $this->analyzer->analyze($data['fulfillment_orders'], time()), $data['pages'], $data['truncated']);
    }
}
