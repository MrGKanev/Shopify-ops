<?php

namespace App\Application\Reports;

use App\Domain\Reports\SameIpAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunSameIpReport extends RunScanReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly SameIpAnalyzer $analyzer) {}

    /** @return ReportResult<array{ip: string, order_count: int, email_count: int, emails: list<string>, orders: list<array<string, mixed>>}> */
    public function handle(Store $store, string $start, string $end): ReportResult
    {
        $result = $this->shopify->sameIpCandidates($store, $start, $end);

        return $this->scanResult($result, 'orders', $this->analyzer->analyze($result['orders']), ['startDate' => $start, 'endDate' => $end]);
    }
}
