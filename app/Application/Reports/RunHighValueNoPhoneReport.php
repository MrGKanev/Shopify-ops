<?php

namespace App\Application\Reports;

use App\Domain\Reports\HighValueNoPhoneAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunHighValueNoPhoneReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly HighValueNoPhoneAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate, float $minimum, ?string $currency): ReportResult
    {
        $result = $this->shopify->highValueOrderCandidates($store, $startDate, $endDate);

        return new ReportResult(rows: $this->analyzer->analyze($result['orders'], $minimum, $currency), scanned: count($result['orders']), pages: $result['pages'], truncated: $result['truncated'], params: ['startDate' => $startDate, 'endDate' => $endDate, 'minimum' => $minimum], meta: ['currency' => $currency]);
    }
}
