<?php

namespace App\Application\Reports;

use App\Domain\Reports\SameIpAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunSameIpReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly SameIpAnalyzer $analyzer) {}

    /** @return ScanResult<array{ip: string, order_count: int, email_count: int, emails: list<string>, orders: list<array<string, mixed>>}> */
    public function handle(Store $store, string $start, string $end): ScanResult
    {
        $result = $this->shopify->sameIpCandidates($store, $start, $end);

        return new ScanResult(scanned: count($result['orders']), rows: $this->analyzer->analyze($result['orders']), pages: $result['pages'], truncated: $result['truncated'], startDate: $start, endDate: $end);
    }
}
