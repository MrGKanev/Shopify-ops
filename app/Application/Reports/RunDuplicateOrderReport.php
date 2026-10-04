<?php

namespace App\Application\Reports;

use App\Domain\Reports\DuplicateOrderAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunDuplicateOrderReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly DuplicateOrderAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $start, string $end): ReportResult
    {
        $result = $this->shopify->duplicateOrderCandidates($store, $start, $end);

        return new ReportResult(rows: $this->analyzer->analyze($result['orders']), scanned: count($result['orders']), pages: $result['pages'], truncated: $result['truncated'], params: ['startDate' => $start, 'endDate' => $end], meta: []);
    }
}
