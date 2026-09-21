<?php

namespace App\Application\Reports;

use App\Domain\Reports\ZombieProductsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunZombieProductsReport extends RunScanReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly ZombieProductsAnalyzer $analyzer) {}

    /** @return ScanResult<array{id: int|string, title: string, vendor: string, type: string, reason: string, stock: int, detail: string}> */
    public function handle(Store $store): ScanResult
    {
        $result = $this->shopify->zombieProductsCandidates($store);

        return $this->scanResult($result, 'products', $this->analyzer->analyze($result['products']));
    }
}
