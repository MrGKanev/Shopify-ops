<?php

namespace Database\Factories;

use App\Models\ShipStationEvent;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShipStationEvent> */
class ShipStationEventFactory extends Factory
{
    public function definition(): array
    {
        return ['store_id' => Store::factory(), 'event_key' => hash('sha256', fake()->uuid()), 'generation' => hash('sha256', 'token'), 'topic' => 'SHIP_NOTIFY', 'payload' => ['resource_url' => 'https://ssapi.shipstation.com/shipments?storeID=12&batchId=99'], 'status' => 'received', 'available_at' => now()];
    }
}
