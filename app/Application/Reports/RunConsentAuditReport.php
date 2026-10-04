<?php

namespace App\Application\Reports;

use App\Domain\Reports\ConsentAuditAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyCustomers;
use App\Models\Store;

class RunConsentAuditReport extends RunScanReport
{
    public function __construct(private readonly ShopifyCustomers $shopify, private readonly ConsentAuditAnalyzer $analyzer) {}

    /** @return ReportResult<array{id: int|string, number: string, created_at: string, email: string, total: float|string, currency: string, email_consent: string, sms_consent: string}> */
    public function handle(Store $store, string $start, string $end): ReportResult
    {
        $result = $this->shopify->consentAuditCandidates($store, $start, $end);

        return $this->scanResult($result, 'orders', $this->analyzer->analyze($result['orders']), ['startDate' => $start, 'endDate' => $end]);
    }
}
