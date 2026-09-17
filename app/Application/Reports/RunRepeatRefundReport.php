<?php

namespace App\Application\Reports;

use App\Domain\Reports\RepeatRefundAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunRepeatRefundReport
{
    /**
     * Create a new class instance.
     */
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly RepeatRefundAnalyzer $analyzer) {}

    /** @return ScanResult<array{email: string, refund_count: int, total_refunded: float|string, orders: list<array<string, mixed>>}> */
    public function handle(Store $store, string $start, string $end, int $minimum): ScanResult
    {
        $result = $this->shopify->repeatRefundCandidates($store, $start, $end);

        return new ScanResult(scanned: count($result['orders']), rows: $this->analyzer->analyze($result['orders'], $minimum), pages: $result['pages'], truncated: $result['truncated'], startDate: $start, endDate: $end, minimum: $minimum);
    }
}
