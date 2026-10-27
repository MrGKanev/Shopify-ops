<?php

namespace App\Application\Orders;

use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Models\Store;

class LoadStoreShipStationShipments
{
    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    public function handle(Store $store, ShipStationClientContract $client, string $number, array $orders): array
    {
        if ($orders === []) {
            return [];
        }
        $orderIds = array_map(fn (array $order): ?int => ShipStationIdentifier::positiveInt($order['orderId'] ?? null), $orders);
        $storeId = ShipStationIdentifier::positiveInt((string) $store->store_number);
        $shipments = [];
        foreach ($client->getOrderShipments($number, true) as $shipment) {
            $shipmentOrderId = ShipStationIdentifier::positiveInt($shipment['orderId'] ?? null);
            if ($shipmentOrderId === null) {
                throw new UnexpectedResponse('ShipStation shipment ownership could not be confirmed.');
            }
            if (! in_array($shipmentOrderId, $orderIds, true)) {
                continue;
            }
            $id = ShipStationIdentifier::positiveInt($shipment['shipmentId'] ?? null);
            if ($id === null
                || (isset($shipment['advancedOptions']['storeId']) && ($storeId === null || ShipStationIdentifier::positiveInt($shipment['advancedOptions']['storeId']) !== $storeId))
                || (isset($shipments[$id]) && $shipments[$id] !== $shipment)) {
                throw new UnexpectedResponse('ShipStation shipment identity could not be confirmed.');
            }
            $shipments[$id] = $shipment;
        }

        return array_values($shipments);
    }
}
