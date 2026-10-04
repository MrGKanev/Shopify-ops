<?php

namespace App\Application\Reports;

use App\Domain\Reports\AddressChangeAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunPostShipAddressChangeReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly AddressChangeAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate): ReportResult
    {
        $data = $this->shopify->addressChangeCandidates($store, $startDate, $endDate);
        $rows = $this->analyzer->postShipRows($data['orders'], $this->analyzer->latestChanges($data['events']));

        return new ReportResult(rows: $rows, scanned: count($rows), pages: $data['pages'], truncated: $data['truncated'], params: ['startDate' => $startDate, 'endDate' => $endDate], meta: []);
    }
}
