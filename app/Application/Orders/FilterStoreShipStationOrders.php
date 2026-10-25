<?php

namespace App\Application\Orders;

use App\Integrations\Exceptions\UnexpectedResponse;
use App\Models\Store;

class FilterStoreShipStationOrders
{
    public function __construct(private readonly VerifyShipStationShipmentOwnership $ownership) {}

    /** @param list<array<string, mixed>> $orders
     * @return list<array<string, mixed>> */
    public function handle(Store $store, array $orders): array
    {
        $storeId = $this->ownership->storeId($store);
        $confirmed = [];
        foreach ($orders as $order) {
            $owner = $order['advancedOptions']['storeId'] ?? null;
            if ((! is_int($owner) && ! is_string($owner)) || ! ctype_digit((string) $owner) || (int) $owner < 1) {
                throw new UnexpectedResponse('ShipStation order store identity could not be confirmed.');
            }
            if ((int) $owner !== $storeId) {
                continue;
            }
            $id = $order['orderId'] ?? null;
            if ((! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id) || (int) $id < 1 || ! is_string($order['orderNumber'] ?? null) || trim($order['orderNumber']) === '') {
                throw new UnexpectedResponse('ShipStation order identity could not be confirmed.');
            }
            $confirmed[] = $order;
        }

        return $confirmed;
    }
}
