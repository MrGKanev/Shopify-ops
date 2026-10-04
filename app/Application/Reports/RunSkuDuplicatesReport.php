<?php

namespace App\Application\Reports;

use App\Domain\Reports\SkuDuplicatesAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyCatalog;
use App\Models\Store;

class RunSkuDuplicatesReport extends RunScanReport
{
    public function __construct(private readonly ShopifyCatalog $shopify, private readonly SkuDuplicatesAnalyzer $analyzer) {}

    /** @return ReportResult<array{sku: string, count: int, variants: list<array<string, mixed>>}> */
    public function handle(Store $store): ReportResult
    {
        $catalogue = $this->shopify->skuDuplicatesCandidates($store);
        $result = $this->analyzer->analyze($catalogue['products']);

        return $this->scanResult($catalogue, 'products', $result['rows'], ['totalVariants' => $result['totalVariants']]);
    }
}
