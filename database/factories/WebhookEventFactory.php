<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
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
            'webhook_id' => fake()->unique()->uuid(),
            'topic' => 'orders/updated',
            'shop_domain' => 'acme.myshopify.com',
            'api_version' => '2026-07',
            'subject_id' => (string) fake()->numberBetween(1000, 9999),
            'status' => 'received',
            'payload' => ['id' => fake()->numberBetween(1000, 9999)],
            'occurred_at' => now(),
        ];
    }
}
