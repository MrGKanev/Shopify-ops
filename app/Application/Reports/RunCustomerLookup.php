<?php

namespace App\Application\Reports;

use App\Domain\Reports\CustomerOrderSummaryAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunCustomerLookup
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly CustomerOrderSummaryAnalyzer $analyzer) {}

    public function handle(Store $store, string $email): CustomerLookupResult
    {
        $result = $this->shopify->customerOrderHistory($store, $email);
        $summary = $this->analyzer->analyze($result['orders']);

        return new CustomerLookupResult($email, $result['orders'], $result['customer'], $summary['total_spent'], $summary['currency'], $summary['paid'], $summary['cancelled'], $summary['tags'], $result['pages'], $result['truncated']);
    }
}
