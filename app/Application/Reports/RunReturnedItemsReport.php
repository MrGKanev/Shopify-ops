<?php

namespace App\Application\Reports;

use App\Domain\Reports\ReturnedItemsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunReturnedItemsReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly ReturnedItemsAnalyzer $analyzer) {}

    /** @return ScanResult<array{product: string, quantity: int}> */
    public function handle(Store $store, string $startDate, string $endDate): ScanResult
    {
        $candidates = $this->shopify->returnedItemCandidates($store, $startDate);

        return new ScanResult(scanned: count($candidates['orders']), rows: $this->analyzer->analyze($candidates['orders'], $startDate, $endDate), pages: $candidates['pages'], truncated: $candidates['truncated'], startDate: $startDate, endDate: $endDate);
    }
}
