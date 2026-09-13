<?php

namespace App\Application\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CheckReadiness
{
    /** @return array{ready: bool, checks: array{database: bool, cache: bool, queue: bool, worker: bool, scheduler: bool}} */
    public function handle(): array
    {
        try {
            DB::select('select 1');
            $database = true;
        } catch (Throwable) {
            $database = false;
        }
        try {
            $cacheKey = 'ready:'.Str::uuid();
            Cache::put($cacheKey, true, 10);
            $cache = Cache::pull($cacheKey) === true;
        } catch (Throwable) {
            $cache = false;
        }

        $queue = trim((string) config('queue.default'));
        $heartbeat = $cache ? Cache::get('health:checks:queue:latestHeartbeatAt.default') : null;
        $worker = $queue === 'sync' || (is_numeric($heartbeat) && now()->timestamp - (int) $heartbeat <= (int) config('security.worker_max_age_seconds'));
        $scheduleHeartbeat = $cache ? Cache::get('health:checks:schedule:latestHeartbeatAt') : null;
        $scheduler = is_numeric($scheduleHeartbeat) && now()->timestamp - (int) $scheduleHeartbeat <= 300;
        $checks = ['database' => $database, 'cache' => $cache, 'queue' => $queue !== '', 'worker' => $worker, 'scheduler' => $scheduler];

        return ['ready' => ! in_array(false, $checks, true), 'checks' => $checks];
    }
}
