<?php

namespace App\Application\Reports;

use App\Domain\Reports\DuplicateOrderAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunDuplicateOrderReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly DuplicateOrderAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): DuplicateOrderResult
    {
        $result = $this->shopify->duplicateOrderCandidates($store, $start, $end);

        return new DuplicateOrderResult($start, $end, count($result['orders']), $this->analyzer->analyze($result['orders']), $result['pages'], $result['truncated']);
    }
}
