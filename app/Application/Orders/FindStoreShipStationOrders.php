<?php

namespace App\Application\Orders;

use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Models\Store;
use LogicException;

class FindStoreShipStationOrders
{
    /**
     * Only orders carrying the requested number are examined: others returned by a fuzzy search are not part of this lookup, so their ownership is irrelevant.
     * Every order that does carry the number must prove it belongs to this store, otherwise the lookup fails instead of guessing.
     *
     * @return list<array<string, mixed>>
     */
    public function handle(Store $store, ShipStationClientContract $client, string $number): array
    {
        $storeNumber = ShipStationIdentifier::positiveInt((string) $store->store_number)
            ?? throw new LogicException('A ShipStation store number is required for order lookups.');
        $matches = [];
        foreach ($client->findByOrderNumber($number, $storeNumber) as $order) {
            if (ltrim((string) ($order['orderNumber'] ?? ''), '#') !== ltrim($number, '#')) {
                continue;
            }
            $ownerStoreId = ShipStationIdentifier::positiveInt($order['advancedOptions']['storeId'] ?? null)
                ?? throw new UnexpectedResponse('ShipStation order store identity could not be confirmed.');
            if ($ownerStoreId !== $storeNumber) {
                continue;
            }
            if (ShipStationIdentifier::positiveInt($order['orderId'] ?? null) === null) {
                throw new UnexpectedResponse('ShipStation order identity could not be confirmed.');
            }
            $matches[] = $order;
        }

        return $matches;
    }
}
