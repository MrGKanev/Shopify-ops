<?php

namespace Database\Factories;

use App\Models\OperationalIssue;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalIssue>
 */
class OperationalIssueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'owner_user_id' => null,
            'source_tool' => 'run_audit',
            'fingerprint' => fake()->unique()->sha256(),
            'title' => 'Missing order #'.fake()->numberBetween(1000, 9999),
            'reference' => '#'.fake()->numberBetween(1000, 9999),
            'status' => 'open',
            'priority' => 'normal',
            'occurrences' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'resolved_at' => null,
            'due_date' => null,
            'resolution_note' => null,
            'payload' => [],
        ];
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (): array => ['owner_user_id' => $user->getKey()]);
    }
}
