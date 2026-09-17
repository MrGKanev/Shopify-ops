<?php

namespace App\Application\Reports;

use App\Domain\Reports\CatalogQualityAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunCatalogQualityReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly CatalogQualityAnalyzer $analyzer) {}

    /** @return ScanResult<array{id: int|string, title: string, vendor: string, type: string, issues: list<string>}> */
    public function handle(Store $store): ScanResult
    {
        $result = $this->shopify->catalogQualityCandidates($store);

        return new ScanResult(scanned: count($result['products']), rows: $this->analyzer->analyze($result['products']), pages: $result['pages'], truncated: $result['truncated']);
    }
}
