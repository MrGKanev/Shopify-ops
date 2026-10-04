<?php

namespace App\Application\Reports;

use App\Domain\Reports\FraudRiskAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;

class RunFraudRiskReport extends RunScanReport
{
    public function __construct(private readonly ShopifyOrders $shopify, private readonly FraudRiskAnalyzer $analyzer) {}

    /** @return ReportResult<array{id: int|string, number: string, created_at: string, email: string, total: float|string, currency: string, financial: string, risk: array{level: string, score: int, signals: list<array{label: string, points: int}>}}> */
    public function handle(Store $store, string $start, string $end): ReportResult
    {
        $result = $this->shopify->fraudRiskCandidates($store, $start, $end);

        return $this->scanResult($result, 'orders', $this->analyzer->analyze($result['orders']), ['startDate' => $start, 'endDate' => $end]);
    }
}
