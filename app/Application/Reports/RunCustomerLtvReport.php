<?php

namespace App\Application\Reports;

use App\Domain\Reports\CustomerLtvAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunCustomerLtvReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly CustomerLtvAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): CustomerLtvResult
    {
        $result = $this->shopify->customerLtvCandidates($store, $start, $end);
        $analysis = $this->analyzer->analyze($result['orders']);

        return new CustomerLtvResult($start, $end, count($result['orders']), $analysis['total_customers'], $analysis['total_revenue'], $analysis['top_customers'], $analysis['cohorts'], $result['pages'], $result['truncated']);
    }
}
