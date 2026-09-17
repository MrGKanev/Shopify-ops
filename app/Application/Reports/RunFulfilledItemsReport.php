<?php

namespace App\Application\Reports;

use App\Domain\Reports\FulfilledItemsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunFulfilledItemsReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly FulfilledItemsAnalyzer $analyzer) {}

    /** @return ScanResult<array{product: string, quantity: int}> */
    public function handle(Store $store, string $startDate, string $endDate): ScanResult
    {
        $candidates = $this->shopify->fulfilledItemCandidates($store, $startDate);

        return new ScanResult(scanned: count($candidates['orders']), rows: $this->analyzer->analyze($candidates['orders'], $startDate, $endDate), pages: $candidates['pages'], truncated: $candidates['truncated'], startDate: $startDate, endDate: $endDate);
    }
}
