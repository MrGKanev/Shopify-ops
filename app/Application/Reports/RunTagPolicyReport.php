<?php

namespace App\Application\Reports;

use App\Domain\Reports\TagPolicyAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunTagPolicyReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly TagPolicyAnalyzer $analyzer) {}

    /**
     * @param  array<string, mixed>  $config
     * @return ScanResult<array{order_number: string, created_at: string, email: string, financial: string, fulfillment: string, tags: list<string>, violations: list<string>, shopify_id: int|string}>
     */
    public function handle(Store $store, string $start, string $end, array $config): ScanResult
    {
        $result = $this->shopify->tagPolicyCandidates($store, $start, $end);

        return new ScanResult(scanned: count($result['orders']), rows: $this->analyzer->analyze($result['orders'], $config), pages: $result['pages'], truncated: $result['truncated'], startDate: $start, endDate: $end);
    }
}
