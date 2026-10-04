<?php

namespace App\Application\Reports;

use App\Domain\Reports\TagUsageAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunTagAuditReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly TagUsageAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate, string $orphanCutoff): ReportResult
    {
        $result = $this->shopify->tagAuditCandidates($store, $startDate, $endDate);

        return new ReportResult(rows: $this->analyzer->analyze($result['orders'], $orphanCutoff), scanned: count($result['orders']), pages: $result['pages'], truncated: $result['truncated'], params: ['startDate' => $startDate, 'endDate' => $endDate], meta: []);
    }
}
