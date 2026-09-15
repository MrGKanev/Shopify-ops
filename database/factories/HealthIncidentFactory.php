<?php

namespace Database\Factories;

use App\Models\HealthIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthIncident>
 */
class HealthIncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'check_name' => 'Database',
            'check_label' => 'Database',
            'severity' => 'failed',
            'summary' => 'Database connection failed.',
            'observations' => 1,
            'started_at' => now()->subMinutes(5),
            'last_observed_at' => now(),
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => ['resolved_at' => now()]);
    }
}
