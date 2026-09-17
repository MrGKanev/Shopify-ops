<?php

namespace App\Application\Reports;

use App\Domain\Reports\ZombieProductsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunZombieProductsReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly ZombieProductsAnalyzer $analyzer) {}

    /** @return ScanResult<array{id: int|string, title: string, vendor: string, type: string, reason: string, stock: int, detail: string}> */
    public function handle(Store $store): ScanResult
    {
        $result = $this->shopify->zombieProductsCandidates($store);

        return new ScanResult(scanned: count($result['products']), rows: $this->analyzer->analyze($result['products']), pages: $result['pages'], truncated: $result['truncated']);
    }
}
