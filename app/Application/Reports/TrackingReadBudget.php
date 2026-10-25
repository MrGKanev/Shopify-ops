<?php

namespace App\Application\Reports;

use App\Integrations\Exceptions\TrackingReadLimitExceeded;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TrackingReadBudget
{
    public function remaining(Store $store): int
    {
        $attempts = (int) (DB::table('tracking_read_budgets')->where('store_id', $store->id)->where('budget_date', CarbonImmutable::now('UTC')->toDateString())->value('attempts') ?? 0);

        return max(0, $store->movementPolicy()['daily_limit'] - $attempts);
    }

    public function claim(Store $store): void
    {
        $now = CarbonImmutable::now('UTC');
        $identity = ['store_id' => $store->id, 'budget_date' => $now->toDateString()];
        DB::table('tracking_read_budgets')->insertOrIgnore([...$identity, 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $claimed = DB::table('tracking_read_budgets')->where($identity)->where('attempts', '<', $store->movementPolicy()['daily_limit'])->increment('attempts', 1, ['updated_at' => $now]);
        if ($claimed !== 1) {
            throw new TrackingReadLimitExceeded;
        }
    }
}
