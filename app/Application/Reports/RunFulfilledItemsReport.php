<?php

namespace App\Application\Reports;

use App\Domain\Reports\FulfilledItemsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunFulfilledItemsReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly FulfilledItemsAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate): FulfilledItemsResult
    {
        $candidates = $this->shopify->fulfilledItemCandidates($store, $startDate);

        return new FulfilledItemsResult($startDate, $endDate, count($candidates['orders']), $this->analyzer->analyze($candidates['orders'], $startDate, $endDate), $candidates['pages'], $candidates['truncated']);
    }
}
