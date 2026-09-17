<?php

namespace App\Application\Reports;

use App\Domain\Reports\CountryMismatchAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunCountryMismatchReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly CountryMismatchAnalyzer $analyzer) {}

    /** @return ScanResult<array{id: int|string, number: string, created_at: string, email: string, total: float|string, currency: string, financial: string, fulfillment: string, billing_name: string, billing_country: string, shipping_country: string}> */
    public function handle(Store $store, string $startDate, string $endDate): ScanResult
    {
        $result = $this->shopify->countryMismatchCandidates($store, $startDate, $endDate);
        $analysis = $this->analyzer->analyze($result['orders']);

        return new ScanResult(scanned: count($result['orders']), rows: $analysis['rows'], pages: $result['pages'], truncated: $result['truncated'], startDate: $startDate, endDate: $endDate, skippedMissingCountry: $analysis['skipped_missing_country']);
    }
}
