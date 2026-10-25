<?php

namespace App\Application\Reports;

use App\Application\Orders\VerifyShipStationShipmentOwnership;
use App\Domain\Reports\VoidedShipmentsAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use LogicException;

class RunVoidedShipmentsReport
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly VoidedShipmentsAnalyzer $analyzer, private readonly ShippingBillingCosts $billing, private readonly VerifyShipStationShipmentOwnership $ownership) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate): ReportResult
    {
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required for the voided shipments report.');

        $storeId = $this->ownership->storeId($store);
        $shipments = $this->ownership->handle($store, $client, $client->fetchVoidedShipments($startDate, $endDate, $storeId));

        $rows = $this->analyzer->analyze($shipments);
        $billing = $this->billing->forShipments($store, array_map(fn (array $row): string => (string) $row['shipment_id'], $rows));
        foreach ($rows as &$row) {
            $row['refund_billing'] = $billing['truncated'] ? null : $this->billing->match($billing['groups'], (string) $row['shipment_id'], $row['ss_order_id'], $row['order_number']);
        }
        unset($row);

        return new ReportResult(rows: $rows, scanned: count($shipments), params: ['startDate' => $startDate, 'endDate' => $endDate]);
    }
}
