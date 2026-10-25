<?php

namespace Database\Seeders;

use App\Models\Store;
use App\UserRole;
use Illuminate\Database\Seeder;

class ReportScheduleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Store::query()->lazyById(100) as $store) {
            $owner = $store->users()->where('role', UserRole::Admin)->first();
            if ($owner === null) {
                continue;
            }
            $store->reportSchedules()->firstOrCreate(['tool' => 'shipment_backlog', 'name' => 'Shipment backlog history'], ['user_id' => $owner->id, 'arguments' => [], 'parameters' => [], 'enabled' => false, 'timezone' => $store->shopTimezone()]);
        }
    }
}
