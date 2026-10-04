<?php

namespace App\Application\Reports;

use App\Domain\Reports\VoidedShipmentsAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use LogicException;

class RunVoidedShipmentsReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly VoidedShipmentsAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate): ReportResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required for the voided shipments report.');

        $shipments = $client->fetchVoidedShipments($startDate, $endDate);

        return new ReportResult(rows: $this->analyzer->analyze($shipments), scanned: count($shipments), params: ['startDate' => $startDate, 'endDate' => $endDate]);
    }
}
