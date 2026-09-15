<?php

namespace Database\Factories;

use App\Models\BackupVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupVerification>
 */
class BackupVerificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'path' => 'Shopify Ops/'.fake()->dateTime()->format('Y-m-d-H-i-s').'.zip',
            'status' => 'verified',
            'archive_size' => fake()->numberBetween(1000, 1000000),
            'checksum' => fake()->sha256(),
            'entries' => 2,
            'database_dump' => 'db-dumps/sqlite-sqlite-database.sql',
            'error' => null,
            'duration_ms' => fake()->numberBetween(10, 1000),
            'verified_at' => now(),
        ];
    }
}
