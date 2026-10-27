<?php

namespace App\Application\Orders;

use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use LogicException;

class ListOpenStoreOrderNumbers
{
    public const MAX_ORDERS = 50;

    public function __construct(private readonly ShipStationClientFactory $clients) {}

    /**
     * Read-only. Open means awaiting shipment or on hold in ShipStation for this store (store ownership is verified by the client).
     * Oldest first, capped; `total` is the full count of distinct open order numbers so callers can say that more exist.
     *
     * @return array{numbers: list<string>, total: int}
     */
    public function handle(Store $store, int $limit = self::MAX_ORDERS): array
    {
        $client = $this->clients->forStore($store);
        $storeNumber = ShipStationIdentifier::positiveInt((string) $store->store_number);
        if ($client === null || $storeNumber === null) {
            throw new LogicException('ShipStation is not configured for this store.');
        }
        $numbers = [];
        foreach ($client->fetchBacklogOrders($storeNumber) as $order) {
            $number = trim((string) ($order['orderNumber'] ?? ''));
            if ($number !== '') {
                $numbers[$number] = $number;
            }
        }
        $numbers = array_values($numbers);

        return ['numbers' => array_slice($numbers, 0, $limit), 'total' => count($numbers)];
    }
}
