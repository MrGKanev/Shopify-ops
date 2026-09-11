<?php

namespace App\Application\Reports;

use App\Models\RunLog;
use App\Models\Store;
use App\Notifications\ReportEmailNotification;
use App\Notifications\ScanDiscordNotification;
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
        $discordRules = $store->resolvedDiscordRules();
        if (($attributes['tool'] ?? '') !== 'run_audit' && ($attributes['status'] ?? '') !== 'error' && $discordRules['scan_enabled'] && $rows >= $discordRules['scan_min_rows'] && trim((string) config('services.discord.notifications.webhook_url')) !== '') {
            Notification::route('discord', config('services.discord.notifications.webhook_url'))->notify(new ScanDiscordNotification($store->label, (string) ($attributes['tool'] ?? 'scan'), $rows));
        }
        $tool = (string) ($attributes['tool'] ?? '');
        $emailRule = $store->resolvedEmailRules()[$tool] ?? null;
        if (($attributes['status'] ?? '') !== 'error' && $emailRule && $emailRule['mode'] === 'immediate' && $emailRule['email'] !== '' && $rows >= $emailRule['threshold'] && ($rows > 0 || $emailRule['include_zero'])) {
            Notification::route('mail', $emailRule['email'])->notify(new ReportEmailNotification($store->label, $tool, $rows, isset($attributes['start_date']) ? (string) $attributes['start_date'] : null, isset($attributes['end_date']) ? (string) $attributes['end_date'] : null));
        }

        return $run;
    }
}
