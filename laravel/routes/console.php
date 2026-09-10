<?php

use App\Models\Store;
use App\Notifications\ReportDigestNotification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;
use Spatie\Health\Models\HealthCheckResultHistoryItem;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reports:email-digest', function (): void {
    Store::whereNotNull('email_rules')->each(function (Store $store): void {
        $byRecipient = [];
        foreach ($store->resolvedEmailRules() as $tool => $rule) {
            if ($rule['mode'] !== 'digest' || $rule['email'] === '') {
                continue;
            }
            $run = $store->runLogs()->where('tool', $tool)->where('status', '!=', 'error')->where('created_at', '>=', now()->subDay())->latest()->first();
            $rows = (int) ($run?->rows_found ?? 0);
            if ($run && $rows >= $rule['threshold'] && ($rows > 0 || $rule['include_zero'])) {
                $byRecipient[$rule['email']][] = ['tool' => $tool, 'rows' => $rows];
            }
        }
        foreach ($byRecipient as $email => $sections) {
            Notification::route('mail', $email)->notify(new ReportDigestNotification($store->label, $sections));
        }
    });
})->purpose('Queue daily report email digests');

Schedule::command('activitylog:clean')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('health:schedule-check-heartbeat')->everyMinute();
Schedule::command('health:queue-check-heartbeat')->everyMinute();
Schedule::command('health:check')->everyMinute()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [HealthCheckResultHistoryItem::class]])->dailyAt('02:45');
Schedule::command('backup:run', ['--only-db'])->dailyAt('01:15')->withoutOverlapping();
Schedule::command('backup:run')->weeklyOn(0, '01:45')->withoutOverlapping();
Schedule::command('backup:monitor')->hourlyAt(20)->withoutOverlapping();
Schedule::command('backup:clean')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('reports:email-digest')->dailyAt('08:00')->withoutOverlapping();

if (config('queue.default') === 'redis') {
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
}
