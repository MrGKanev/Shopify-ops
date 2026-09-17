<?php

namespace App\Application\Reports;

use App\Domain\Reports\SkuDuplicatesAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunSkuDuplicatesReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly SkuDuplicatesAnalyzer $analyzer) {}

    /** @return ScanResult<array{sku: string, count: int, variants: list<array<string, mixed>>}> */
    public function handle(Store $store): ScanResult
    {
        $catalogue = $this->shopify->skuDuplicatesCandidates($store);
        $result = $this->analyzer->analyze($catalogue['products']);

        return new ScanResult(scanned: count($catalogue['products']), rows: $result['rows'], pages: $catalogue['pages'], truncated: $catalogue['truncated'], totalVariants: $result['totalVariants']);
    }
}
