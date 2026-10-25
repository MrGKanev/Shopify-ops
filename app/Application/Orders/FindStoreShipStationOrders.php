<?php

namespace App\Application\Orders;

use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Models\Store;
use LogicException;

class FindStoreShipStationOrders
{
    /** @return list<array<string, mixed>> */
    public function handle(Store $store, ShipStationClientContract $client, string $number): array
    {
        $storeNumber = (string) $store->store_number;
        if (! ctype_digit($storeNumber) || (int) $storeNumber < 1) {
            throw new LogicException('A ShipStation store number is required for order lookups.');
        }
        $matches = [];
        foreach ($client->findByOrderNumber($number, (int) $storeNumber) as $order) {
            if (ltrim((string) ($order['orderNumber'] ?? ''), '#') !== ltrim($number, '#')) {
                continue;
            }
            $ownerStoreId = $order['advancedOptions']['storeId'] ?? null;
            if (! is_scalar($ownerStoreId) || ! ctype_digit((string) $ownerStoreId) || (int) $ownerStoreId < 1) {
                throw new UnexpectedResponse('ShipStation order store identity could not be confirmed.');
            }
            if ((string) $ownerStoreId !== $storeNumber) {
                continue;
            }
            if (! ctype_digit((string) ($order['orderId'] ?? '')) || (int) $order['orderId'] < 1) {
                throw new UnexpectedResponse('ShipStation order identity could not be confirmed.');
            }
            $matches[] = $order;
        }

        return $matches;
    }
}
