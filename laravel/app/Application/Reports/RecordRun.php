<?php

namespace App\Application\Reports;

use App\Models\RunLog;
use App\Models\Store;

class RecordRun
{
    /** @param array<string, mixed> $attributes */
    public function handle(Store $store, array $attributes): RunLog
    {
        $run = $store->runLogs()->create($attributes);
        $oldIds = $store->runLogs()->latest('id')->skip(500)->take(500)->pluck('id');
        if ($oldIds->isNotEmpty()) {
            RunLog::whereKey($oldIds)->delete();
        }

        return $run;
    }
}
