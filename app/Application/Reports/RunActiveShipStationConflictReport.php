<?php

namespace App\Application\Reports;

use App\Domain\Reports\ActiveShipStationConflictAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use LogicException;

class RunActiveShipStationConflictReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShopifyAdminGateway $shopify, private readonly ActiveShipStationConflictAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): ActiveShipStationConflictResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required.');
        $ss = $client->fetchActiveOrders();
        $sh = $this->shopify->itemMismatchCandidates($store, $start, $end);
        $data = $this->analyzer->analyze($sh['orders'], $ss);

        return new ActiveShipStationConflictResult($start, $end, $data['scanned'], count($ss), $data['rows'], $sh['pages'], $sh['truncated']);
    }
}
