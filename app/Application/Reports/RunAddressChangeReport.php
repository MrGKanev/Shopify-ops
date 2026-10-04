<?php

namespace App\Application\Reports;

use App\Domain\Reports\AddressChangeAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunAddressChangeReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly AddressChangeAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $start, string $end): ReportResult
    {
        $data = $this->shopify->addressChangeCandidates($store, $start, $end);
        $changes = $this->analyzer->latestChanges($data['events']);

        return new ReportResult(rows: $this->analyzer->rows($data['orders'], $changes), scanned: count($this->analyzer->rows($data['orders'], $changes)), pages: $data['pages'], truncated: $data['truncated'], params: ['startDate' => $start, 'endDate' => $end], meta: []);
    }
}
