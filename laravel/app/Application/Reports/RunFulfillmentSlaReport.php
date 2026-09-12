<?php

namespace App\Application\Reports;

use App\Domain\Reports\FulfillmentSlaAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunFulfillmentSlaReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly FulfillmentSlaAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate, int $threshold): FulfillmentSlaResult
    {
        $data = $this->shopify->fulfillmentSlaCandidates($store, $startDate, $endDate);

        return new FulfillmentSlaResult($startDate, $endDate, $threshold, count($data['orders']), $this->analyzer->analyze($data['orders'], $threshold, time()), $data['pages'], $data['truncated']);
    }
}
