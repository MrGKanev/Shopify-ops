<?php

namespace App\Application\Reports;

use App\Domain\Reports\ZombieProductsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyCatalog;
use App\Models\Store;

class RunZombieProductsReport extends RunScanReport
{
    public function __construct(private readonly ShopifyCatalog $shopify, private readonly ZombieProductsAnalyzer $analyzer) {}

    /** @return ReportResult<array{id: int|string, title: string, vendor: string, type: string, reason: string, stock: int|null, detail: string}> */
    public function handle(Store $store): ReportResult
    {
        $result = $this->shopify->zombieProductsCandidates($store);

        return $this->scanResult($result, 'products', $this->analyzer->analyze($result['products']));
    }
}
