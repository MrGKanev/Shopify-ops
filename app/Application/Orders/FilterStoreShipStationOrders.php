<?php

namespace App\Application\Orders;

use App\Integrations\Exceptions\UnexpectedResponse;
use App\Models\Store;

class FilterStoreShipStationOrders
{
    public function __construct(private readonly VerifyShipStationShipmentOwnership $ownership) {}

    /**
     * Every given order is in scope, so one whose store cannot be read fails the call even if it may belong to another store.
     *
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    public function handle(Store $store, array $orders): array
    {
        $storeId = $this->ownership->storeId($store);
        $confirmed = [];
        foreach ($orders as $order) {
            $owner = ShipStationIdentifier::positiveInt($order['advancedOptions']['storeId'] ?? null)
                ?? throw new UnexpectedResponse('ShipStation order store identity could not be confirmed.');
            if ($owner !== $storeId) {
                continue;
            }
            if (ShipStationIdentifier::positiveInt($order['orderId'] ?? null) === null || ! is_string($order['orderNumber'] ?? null) || trim($order['orderNumber']) === '') {
                throw new UnexpectedResponse('ShipStation order identity could not be confirmed.');
            }
            $confirmed[] = $order;
        }

        return $confirmed;
    }
}
