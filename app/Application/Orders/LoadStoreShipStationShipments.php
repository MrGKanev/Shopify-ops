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
        $orderIds = array_map(fn (array $order): string => (string) $order['orderId'], $orders);
        $shipments = [];
        foreach ($client->getOrderShipments($number, true) as $shipment) {
            if (! is_scalar($shipment['orderId'] ?? null)) {
                throw new UnexpectedResponse('ShipStation shipment ownership could not be confirmed.');
            }
            if (! in_array((string) $shipment['orderId'], $orderIds, true)) {
                continue;
            }
            $id = (string) ($shipment['shipmentId'] ?? '');
            if (! ctype_digit($id) || (int) $id < 1
                || (isset($shipment['advancedOptions']['storeId']) && (string) $shipment['advancedOptions']['storeId'] !== (string) $store->store_number)
                || (isset($shipments[$id]) && $shipments[$id] !== $shipment)) {
                throw new UnexpectedResponse('ShipStation shipment identity could not be confirmed.');
            }
            $shipments[$id] = $shipment;
        }

        return array_values($shipments);
    }
}
