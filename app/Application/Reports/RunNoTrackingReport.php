<?php

namespace App\Application\Reports;

use App\Domain\Reports\NoTrackingAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunNoTrackingReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly NoTrackingAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate, int $threshold): NoTrackingResult
    {
        $data = $this->shopify->noTrackingCandidates($store, $startDate);

        return new NoTrackingResult($startDate, $endDate, $threshold, count($data['orders']), $this->analyzer->analyze($data['orders'], $startDate, $endDate, $threshold, time()), $data['pages'], $data['truncated']);
    }
}
