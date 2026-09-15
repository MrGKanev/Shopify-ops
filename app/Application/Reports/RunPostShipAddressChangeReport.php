<?php

namespace App\Application\Reports;

use App\Domain\Reports\AddressChangeAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunPostShipAddressChangeReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly AddressChangeAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate): PostShipAddressChangeResult
    {
        $data = $this->shopify->addressChangeCandidates($store, $startDate, $endDate);
        $rows = $this->analyzer->postShipRows($data['orders'], $this->analyzer->latestChanges($data['events']));

        return new PostShipAddressChangeResult($startDate, $endDate, $rows, $data['pages'], $data['truncated']);
    }
}
