<?php

namespace App\Application\Reports;

use App\Domain\Reports\CarrierPerformanceAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use LogicException;

class RunCarrierPerformanceReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly CarrierPerformanceAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate): CarrierPerformanceResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required for the carrier performance report.');
        $shipments = $client->fetchShipmentsByDate($startDate, $endDate);

        return new CarrierPerformanceResult($startDate, $endDate, count($shipments), $this->analyzer->analyze($shipments));
    }
}
