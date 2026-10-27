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
        return ShipStationIdentifier::positiveInt((string) $store->store_number)
            ?? throw new LogicException('A ShipStation store number is required for shipment reports.');
    }

    /**
     * @param  list<array<string, mixed>>  $shipments
     * @return list<array<string, mixed>>
     */
    public function handle(Store $store, ShipStationClientContract $client, array $shipments): array
    {
        $storeId = $this->storeId($store);
        $confirmed = [];
        $parents = [];
        foreach ($shipments as $shipment) {
            $id = ShipStationIdentifier::positiveInt($shipment['shipmentId'] ?? null);
            $orderId = ShipStationIdentifier::positiveInt($shipment['orderId'] ?? null);
            if ($id === null || $orderId === null || (isset($confirmed[$id]) && $confirmed[$id] !== $shipment)) {
                throw new UnexpectedResponse('Shipment identity could not be confirmed.');
            }
            $parent = $parents[$orderId] ??= $client->getOrder($orderId);
            if (ShipStationIdentifier::positiveInt($parent['orderId'] ?? null) !== $orderId
                || ShipStationIdentifier::positiveInt($parent['advancedOptions']['storeId'] ?? null) !== $storeId
                || (isset($shipment['advancedOptions']['storeId']) && ShipStationIdentifier::positiveInt($shipment['advancedOptions']['storeId']) !== $storeId)
                || ltrim((string) ($parent['orderNumber'] ?? ''), '#') !== ltrim((string) ($shipment['orderNumber'] ?? ''), '#')) {
                throw new UnexpectedResponse('Shipment parent ownership could not be confirmed.');
            }
            $confirmed[$id] = $shipment;
        }

        return array_values($confirmed);
    }
}
