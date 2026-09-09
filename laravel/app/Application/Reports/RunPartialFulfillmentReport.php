<?php

namespace App\Application\Reports;

use App\Domain\Reports\PartialFulfillmentAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunPartialFulfillmentReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly PartialFulfillmentAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate, int $threshold): PartialFulfillmentResult
    {
        $data = $this->shopify->partialFulfillmentCandidates($store, $startDate, $endDate);

        return new PartialFulfillmentResult($startDate, $endDate, $threshold, count($data['orders']), $this->analyzer->analyze($data['orders'], $threshold, time()), $data['pages'], $data['truncated']);
    }
}
