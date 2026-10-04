<?php

namespace App\Application\Reports;

use App\Domain\Reports\NoteFlagAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunNoteFlagReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly NoteFlagAnalyzer $analyzer) {}

    /**
     * @param list<string> $keywords
     * @return ReportResult<array<string, mixed>>

     * @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $start, string $end, array $keywords): ReportResult
    {
        $result = $this->shopify->noteFlagCandidates($store, $start, $end);

        return new ReportResult(rows: $this->analyzer->analyze($result['orders'], $keywords), scanned: count($result['orders']), pages: $result['pages'], truncated: $result['truncated'], params: ['startDate' => $start, 'endDate' => $end, 'keywords' => $keywords], meta: []);
    }
}
