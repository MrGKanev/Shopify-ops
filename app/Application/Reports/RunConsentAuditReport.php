<?php

namespace App\Application\Reports;

use App\Domain\Reports\ConsentAuditAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunConsentAuditReport extends RunScanReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly ConsentAuditAnalyzer $analyzer) {}

    /** @return ScanResult<array{id: int|string, number: string, created_at: string, email: string, total: float|string, currency: string, email_consent: bool, sms_consent: bool}> */
    public function handle(Store $store, string $start, string $end): ScanResult
    {
        $result = $this->shopify->consentAuditCandidates($store, $start, $end);

        return $this->scanResult($result, 'orders', $this->analyzer->analyze($result['orders']), ['startDate' => $start, 'endDate' => $end]);
    }
}
