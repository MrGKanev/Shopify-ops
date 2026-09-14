<?php

namespace App\Application\Reports;

use App\Domain\Reports\ShipmentAgingAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use LogicException;

class RunShipmentAgingReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShipmentAgingAnalyzer $analyzer) {}

    public function handle(Store $store, int $threshold): ShipmentAgingResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required for shipment aging.');
        $orders = $client->fetchAwaitingOrders();
        $data = $this->analyzer->analyze($orders, $threshold, time());

        return new ShipmentAgingResult($threshold, count($orders), $data['rows'], $data['by_sku'], $data['by_type']);
    }
}
