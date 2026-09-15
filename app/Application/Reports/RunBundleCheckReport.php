<?php

namespace App\Application\Reports;

use App\Domain\Reports\BundleCheckAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunBundleCheckReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly BundleCheckAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate): BundleCheckResult
    {
        $data = $this->shopify->fulfillmentSlaCandidates($store, $startDate, $endDate);

        return new BundleCheckResult($startDate, $endDate, count($data['orders']), $this->analyzer->analyze($data['orders']), $data['pages'], $data['truncated']);
    }
}
