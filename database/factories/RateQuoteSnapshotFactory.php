<?php

namespace Database\Factories;

use App\Models\RateQuoteSnapshot;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RateQuoteSnapshot> */
class RateQuoteSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return ['store_id' => Store::factory(), 'user_id' => User::factory()->operator(), 'order_number' => '1001', 'status' => 'queued', 'mode' => 'simulation', 'input' => []];
    }
}
