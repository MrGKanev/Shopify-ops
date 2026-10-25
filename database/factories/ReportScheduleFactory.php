<?php

namespace Database\Factories;

use App\Models\ReportSchedule;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportSchedule> */
class ReportScheduleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['store_id' => Store::factory(), 'user_id' => User::factory()->admin(), 'name' => 'Shipment backlog history', 'tool' => 'shipment_backlog', 'arguments' => [], 'parameters' => [], 'uses_date_range' => false, 'enabled' => false, 'frequency' => 'daily', 'time' => '07:00', 'timezone' => 'UTC', 'weekday' => 1, 'month_day' => 1, 'lookback_days' => 30, 'end_offset_days' => 1, 'notification_enabled' => false, 'notification_threshold' => 1];
    }
}
