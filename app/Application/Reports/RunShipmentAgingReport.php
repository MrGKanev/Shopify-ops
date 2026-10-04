<?php

namespace App\Application\Reports;

use App\Domain\Reports\ShipmentAgingAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use LogicException;

class RunShipmentAgingReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShipmentAgingAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, int $threshold): ReportResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required for shipment aging.');
        $orders = $client->fetchAwaitingOrders();
        $data = $this->analyzer->analyze($orders, $threshold, time());

        return new ReportResult(rows: $data['rows'], scanned: count($orders), pages: 0, truncated: false, params: ['threshold' => $threshold], meta: ['bySku' => $data['by_sku'], 'byType' => $data['by_type']]);
    }
}
