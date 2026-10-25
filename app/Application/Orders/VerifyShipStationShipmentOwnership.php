<?php

namespace App\Application\Orders;

use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Models\Store;
use LogicException;

class VerifyShipStationShipmentOwnership
{
    public function storeId(Store $store): int
    {
        $number = (string) $store->store_number;
        if (! ctype_digit($number) || (int) $number < 1) {
            throw new LogicException('A ShipStation store number is required for shipment reports.');
        }

        return (int) $number;
    }

    /**
     * @param  list<array<string, mixed>>  $shipments
     * @return list<array<string, mixed>>
     */
    public function handle(Store $store, ShipStationClientContract $client, array $shipments): array
    {
        $storeNumber = (string) $this->storeId($store);
        $confirmed = [];
        $parents = [];
        foreach ($shipments as $shipment) {
            $id = (string) ($shipment['shipmentId'] ?? '');
            $orderId = (string) ($shipment['orderId'] ?? '');
            if (! ctype_digit($id) || (int) $id < 1 || ! ctype_digit($orderId) || (int) $orderId < 1
                || (isset($confirmed[$id]) && $confirmed[$id] !== $shipment)) {
                throw new UnexpectedResponse('Shipment identity could not be confirmed.');
            }
            $parent = $parents[$orderId] ??= $client->getOrder((int) $orderId);
            if ((string) ($parent['orderId'] ?? '') !== $orderId
                || (string) ($parent['advancedOptions']['storeId'] ?? '') !== $storeNumber
                || (isset($shipment['advancedOptions']['storeId']) && (string) $shipment['advancedOptions']['storeId'] !== $storeNumber)
                || ltrim((string) ($parent['orderNumber'] ?? ''), '#') !== ltrim((string) ($shipment['orderNumber'] ?? ''), '#')) {
                throw new UnexpectedResponse('Shipment parent ownership could not be confirmed.');
            }
            $confirmed[$id] = $shipment;
        }

        return array_values($confirmed);
    }
}
