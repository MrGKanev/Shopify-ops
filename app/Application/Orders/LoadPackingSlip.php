<?php

namespace App\Application\Orders;

use App\Domain\Orders\PackingSlipBuilder;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use LogicException;

class LoadPackingSlip
{
    public function __construct(private readonly ShipStationClientFactory $clients, private readonly PackingSlipBuilder $builder, private readonly FindStoreShipStationOrders $findOrders) {}

    /** @return array{status: string, slip: array<string, mixed>|null} */
    public function handle(Store $store, string $number): array
    {
        $client = $this->clients->forStore($store);
        if ($client === null) {
            throw new LogicException('ShipStation is not configured for this store.');
        }
        $matches = $this->findOrders->handle($store, $client, $number);

        return match (count($matches)) {
            0 => ['status' => 'not_found', 'slip' => null],
            1 => ['status' => 'ready', 'slip' => $this->builder->build($matches[0])],
            default => ['status' => 'ambiguous', 'slip' => null],
        };
    }
}
