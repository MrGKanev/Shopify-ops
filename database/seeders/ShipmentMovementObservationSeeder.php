<?php

namespace Database\Seeders;

use App\Models\ShipmentMovementObservation;
use Illuminate\Database\Seeder;
use LogicException;

class ShipmentMovementObservationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Synthetic tracking observations may only be seeded in tests.');
        }
        ShipmentMovementObservation::factory()->create(['carrier_acceptance_at' => '2026-10-23 10:00:00']);
    }
}
