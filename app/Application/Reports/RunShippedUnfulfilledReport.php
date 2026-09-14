<?php

namespace App\Application\Reports;

use App\Domain\Reports\ShippedUnfulfilledAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use LogicException;

class RunShippedUnfulfilledReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShopifyAdminGateway $shopify, private readonly ShippedUnfulfilledAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): ShippedUnfulfilledResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required.');
        $ss = $client->fetchAllOrders($start, $end);
        $sh = $this->shopify->itemMismatchCandidates($store, $start, $end);
        $data = $this->analyzer->analyze($ss, $sh['orders']);

        return new ShippedUnfulfilledResult($start, $end, $data['shipped_total'], $data['rows'], $sh['pages'], $sh['truncated']);
    }
}
