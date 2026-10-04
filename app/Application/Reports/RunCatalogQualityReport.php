<?php

namespace App\Application\Reports;

use App\Domain\Reports\CatalogQualityAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyCatalog;
use App\Models\Store;

class RunCatalogQualityReport extends RunScanReport
{
    public function __construct(private readonly ShopifyCatalog $shopify, private readonly CatalogQualityAnalyzer $analyzer) {}

    /** @return ReportResult<array{id: int|string, title: string, vendor: string, type: string, issues: list<string>}> */
    public function handle(Store $store): ReportResult
    {
        $result = $this->shopify->catalogQualityCandidates($store);

        return $this->scanResult($result, 'products', $this->analyzer->analyze($result['products']));
    }
}
