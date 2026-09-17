<?php

namespace App\Application\Reports;

use App\Domain\Reports\ConsentAuditAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunConsentAuditReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly ConsentAuditAnalyzer $analyzer) {}

    /** @return ScanResult<array{id: int|string, number: string, created_at: string, email: string, total: float|string, currency: string, email_consent: bool, sms_consent: bool}> */
    public function handle(Store $store, string $start, string $end): ScanResult
    {
        $result = $this->shopify->consentAuditCandidates($store, $start, $end);

        return new ScanResult(scanned: count($result['orders']), rows: $this->analyzer->analyze($result['orders']), pages: $result['pages'], truncated: $result['truncated'], startDate: $start, endDate: $end);
    }
}
