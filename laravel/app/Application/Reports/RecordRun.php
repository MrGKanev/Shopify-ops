<?php

namespace App\Application\Reports;

use App\Models\RunLog;
use App\Models\Store;
use App\Notifications\ScanSlackNotification;
use Illuminate\Support\Facades\Notification;

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
        $rules = $store->resolvedSlackRules();
        $rows = (int) ($attributes['rows_found'] ?? 0);
        if (($attributes['tool'] ?? '') !== 'run_audit' && ($attributes['status'] ?? '') !== 'error' && $rules['scan_enabled'] && $rows >= $rules['scan_min_rows'] && trim((string) config('services.slack.notifications.webhook_url')) !== '') {
            Notification::route('slack', config('services.slack.notifications.webhook_url'))->notify(new ScanSlackNotification($store->label, (string) ($attributes['tool'] ?? 'scan'), $rows, $rules['mentions']));
        }

        return $run;
    }
}
