<?php

namespace App\Application\Reports;

use App\Domain\Reports\FraudRiskAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunFraudRiskReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly FraudRiskAnalyzer $analyzer) {}

    /** @return ScanResult<array{id: int|string, number: string, created_at: string, email: string, total: float|string, currency: string, financial: string, risk: array{level: string, score: int, signals: list<array{label: string, points: int}>}}> */
    public function handle(Store $store, string $start, string $end): ScanResult
    {
        $result = $this->shopify->fraudRiskCandidates($store, $start, $end);

        return new ScanResult(scanned: count($result['orders']), rows: $this->analyzer->analyze($result['orders']), pages: $result['pages'], truncated: $result['truncated'], startDate: $start, endDate: $end);
    }
}
