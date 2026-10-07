<?php

namespace Database\Factories;

use App\Models\RemediationRun;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RemediationRun> */
class RemediationRunFactory extends Factory
{
    public function definition(): array
    {
        return ['store_id' => Store::factory(), 'user_id' => User::factory()->operator(), 'group_uuid' => (string) Str::uuid(), 'order_number' => '1001', 'shopify_id' => '1', 'action' => 'add_tag', 'status' => 'draft', 'plan' => [], 'expires_at' => now()->addMinutes(15)];
    }
}
