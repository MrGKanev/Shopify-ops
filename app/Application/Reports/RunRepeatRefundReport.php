<?php

namespace App\Application\Reports;

use App\Domain\Reports\RepeatRefundAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Models\Store;

class RunRepeatRefundReport extends RunScanReport
{
    /**
     * Create a new class instance.
     */
    public function __construct(private readonly ShopifyPayments $shopify, private readonly RepeatRefundAnalyzer $analyzer) {}

    /** @return ReportResult<array{email: string, refund_count: int, total_refunded: float|string, orders: list<array<string, mixed>>}> */
    public function handle(Store $store, string $start, string $end, int $minimum): ReportResult
    {
        $result = $this->shopify->repeatRefundCandidates($store, $start, $end);

        return $this->scanResult($result, 'orders', $this->analyzer->analyze($result['orders'], $minimum), ['startDate' => $start, 'endDate' => $end, 'minimum' => $minimum]);
    }
}
