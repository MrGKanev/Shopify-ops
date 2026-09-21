<?php

namespace App\Application\Reports;

use App\Domain\Reports\SameIpAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunSameIpReport extends RunScanReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly SameIpAnalyzer $analyzer) {}

    /** @return ScanResult<array{ip: string, order_count: int, email_count: int, emails: list<string>, orders: list<array<string, mixed>>}> */
    public function handle(Store $store, string $start, string $end): ScanResult
    {
        $result = $this->shopify->sameIpCandidates($store, $start, $end);

        return $this->scanResult($result, 'orders', $this->analyzer->analyze($result['orders']), ['startDate' => $start, 'endDate' => $end]);
    }
}
