<?php

namespace App\Application\Reports;

use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use Carbon\CarbonImmutable;
use LogicException;

class RunShipmentMovementReport
{
    public function __construct(private readonly ShipStationClientFactory $clients, private readonly CheckShipmentMovements $checker) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $orderNumber, int $acceptanceHours, int $staleHours): ReportResult
    {
        $storeNumber = (string) $store->store_number;
        if (! ctype_digit($storeNumber) || (int) $storeNumber < 1 || trim((string) $store->shipstation_v2_api_key) === '') {
            throw new LogicException('V1 store identity and a V2 tracking key are required.');
        }
        $client = $this->clients->forStore($store) ?? throw new LogicException('ShipStation is not configured.');
        $orders = $client->findByOrderNumber($orderNumber, (int) $storeNumber);
        if (count($orders) !== 1 || (string) ($orders[0]['advancedOptions']['storeId'] ?? '') !== $storeNumber || ltrim((string) ($orders[0]['orderNumber'] ?? ''), '#') !== ltrim($orderNumber, '#') || ! ctype_digit((string) ($orders[0]['orderId'] ?? '')) || (int) $orders[0]['orderId'] < 1) {
            throw new UnexpectedResponse('A single store-scoped order is required for movement checks.');
        }
        $orderId = (string) $orders[0]['orderId'];
        $date = CarbonImmutable::now('America/Los_Angeles');
        $shipments = $client->getOrderCostShipments((int) $orderId, (int) $storeNumber, $date->subYear()->toDateString(), $date->toDateString());
        $labels = [];
        foreach ($shipments as $shipment) {
            $id = (string) ($shipment['shipmentId'] ?? '');
            if (! ctype_digit($id) || (int) $id < 1 || (string) ($shipment['orderId'] ?? '') !== $orderId || (isset($shipment['advancedOptions']['storeId']) && (string) $shipment['advancedOptions']['storeId'] !== $storeNumber) || (isset($labels[$id]) && $labels[$id] !== $shipment)) {
                throw new UnexpectedResponse('Shipment ownership or identity could not be confirmed.');
            }
            $labels[$id] = $shipment;
        }
        $verified = array_map(fn (array $shipment): array => [...$shipment, 'orderNumber' => $orderNumber], array_values($labels));
        $result = $this->checker->handle($store, $verified, $acceptanceHours, $staleHours);

        return new ReportResult(rows: $result->rows, scanned: $result->scanned, meta: $result->meta, params: compact('orderNumber', 'acceptanceHours', 'staleHours'));
    }
}
