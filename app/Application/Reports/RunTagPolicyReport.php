<?php

namespace App\Application\Reports;

use App\Domain\Reports\TagPolicyAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunTagPolicyReport extends RunScanReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly TagPolicyAnalyzer $analyzer) {}

    /**
     * @param  array<string, mixed>  $config
     * @return ReportResult<array{order_number: string, created_at: string, email: string, financial: string, fulfillment: string, tags: list<string>, violations: list<array{type: string, name: string, detail: string}>, shopify_id: int|string}>
     */
    public function handle(Store $store, string $start, string $end, array $config): ReportResult
    {
        $result = $this->shopify->tagPolicyCandidates($store, $start, $end);

        return $this->scanResult($result, 'orders', $this->analyzer->analyze($result['orders'], $config), ['startDate' => $start, 'endDate' => $end]);
    }
}
