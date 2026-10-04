<?php

namespace App\Application\Reports;

use App\Domain\Reports\CustomerOrderSummaryAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyCustomers;
use App\Models\Store;

class RunCustomerLookup
{
    public function __construct(private readonly ShopifyCustomers $shopify, private readonly CustomerOrderSummaryAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $email): ReportResult
    {
        $result = $this->shopify->customerOrderHistory($store, $email);
        $summary = $this->analyzer->analyze($result['orders']);

        return new ReportResult(rows: $result['orders'], scanned: count($result['orders']), pages: $result['pages'], truncated: $result['truncated'], params: ['email' => $email], meta: ['customer' => $result['customer'], 'totalSpent' => $summary['total_spent'], 'currency' => $summary['currency'], 'paid' => $summary['paid'], 'cancelled' => $summary['cancelled'], 'tags' => $summary['tags']]);
    }
}
