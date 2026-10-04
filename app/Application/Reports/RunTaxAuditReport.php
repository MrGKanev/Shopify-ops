<?php

namespace App\Application\Reports;

use App\Domain\Reports\TaxAuditAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Models\Store;

class RunTaxAuditReport extends RunScanReport
{
    public function __construct(private readonly ShopifyPayments $shopify, private readonly TaxAuditAnalyzer $analyzer) {}

    /** @return ReportResult<array{id: int|string, number: string, created_at: string, email: string, total: float|string, currency: string}> */
    public function handle(Store $store, string $start, string $end, float $minimum): ReportResult
    {
        $result = $this->shopify->taxAuditCandidates($store, $start, $end);

        return $this->scanResult($result, 'orders', $this->analyzer->analyze($result['orders'], $minimum), ['startDate' => $start, 'endDate' => $end]);
    }
}
