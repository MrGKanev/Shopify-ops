<?php

namespace App\Application\Reports;

use App\Application\Orders\VerifyShipStationShipmentOwnership;
use App\Domain\Reports\CarrierPerformanceAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use LogicException;

class RunCarrierPerformanceReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly CarrierPerformanceAnalyzer $analyzer, private readonly VerifyShipStationShipmentOwnership $ownership) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate): ReportResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required for the carrier performance report.');
        $storeId = $this->ownership->storeId($store);
        $shipments = $this->ownership->handle($store, $client, $client->fetchShipmentsByDate($startDate, $endDate, $storeId));

        return new ReportResult(rows: $this->analyzer->analyze($shipments), scanned: count($shipments), pages: 0, truncated: false, params: ['startDate' => $startDate, 'endDate' => $endDate], meta: []);
    }
}
