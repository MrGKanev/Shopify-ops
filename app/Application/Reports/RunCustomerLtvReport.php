<?php

namespace App\Application\Reports;

use App\Domain\Reports\CustomerLtvAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyCustomers;
use App\Models\Store;

class RunCustomerLtvReport
{
    public function __construct(private readonly ShopifyCustomers $shopify, private readonly CustomerLtvAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $start, string $end): ReportResult
    {
        $result = $this->shopify->customerLtvCandidates($store, $start, $end);
        $analysis = $this->analyzer->analyze($result['orders']);

        return new ReportResult(rows: $analysis['top_customers'], scanned: count($result['orders']), pages: $result['pages'], truncated: $result['truncated'], params: ['startDate' => $start, 'endDate' => $end], meta: ['customers' => $analysis['total_customers'], 'revenue' => $analysis['total_revenue'], 'cohorts' => $analysis['cohorts']]);
    }
}
