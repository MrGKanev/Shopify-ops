<?php

namespace App\Application\Orders;

use App\Domain\Orders\PickListBuilder;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use LogicException;

class LoadPickList
{
    public function __construct(private readonly ShipStationClientFactory $clients, private readonly PickListBuilder $builder, private readonly FindStoreShipStationOrders $findOrders) {}

    /**
     * Orders that are not found or match more than once are listed instead of silently dropped, so totals are never presented as complete.
     *
     * @param  list<string>  $orderNumbers
     * @return array{lines: list<array{sku: string, name: string, options: string, location: string, image: string, quantity: int, allocations: list<array{order: string, quantity: int}>}>, totalQuantity: int, warnings: list<string>, orderCount: int, missing: list<string>, ambiguous: list<string>}
     */
    public function handle(Store $store, array $orderNumbers, string $sort = 'location'): array
    {
        $client = $this->clients->forStore($store);
        if ($client === null) {
            throw new LogicException('ShipStation is not configured for this store.');
        }
        $orders = [];
        $missing = [];
        $ambiguous = [];
        foreach ($orderNumbers as $number) {
            $matches = $this->findOrders->handle($store, $client, $number);
            match (count($matches)) {
                0 => $missing[] = $number,
                1 => $orders[] = $matches[0],
                default => $ambiguous[] = $number,
            };
        }

        return $this->builder->build($orders, $sort) + ['orderCount' => count($orders), 'missing' => $missing, 'ambiguous' => $ambiguous];
    }
}
