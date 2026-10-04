<?php

namespace App\Application\Reports;

use App\Domain\Reports\OrderEditAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunOrderEditReport
{
    /**
     * Create a new class instance.
     */
    public function __construct(private readonly ShopifyOrders $shopify, private readonly OrderEditAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $start, string $end): ReportResult
    {
        $data = $this->shopify->orderEditCandidates($store, $start, $end);

        return new ReportResult(rows: $this->analyzer->rows($data['orders'], $this->analyzer->group($data['events'])), scanned: count($this->analyzer->rows($data['orders'], $this->analyzer->group($data['events']))), pages: $data['pages'], truncated: $data['truncated'], params: ['startDate' => $start, 'endDate' => $end], meta: []);
    }
}
