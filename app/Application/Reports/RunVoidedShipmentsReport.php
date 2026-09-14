<?php

namespace App\Application\Reports;

use App\Domain\Reports\VoidedShipmentsAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use LogicException;

class RunVoidedShipmentsReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly VoidedShipmentsAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate): VoidedShipmentsResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required for the voided shipments report.');

        return new VoidedShipmentsResult($startDate, $endDate, $this->analyzer->analyze($client->fetchVoidedShipments($startDate, $endDate)));
    }
}
