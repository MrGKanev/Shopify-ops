<?php

use App\Application\Health\CheckOperationalAlerts;
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
            $recipient = $rule['email'] !== '' ? $rule['email'] : trim((string) ($store->default_alert_email ?? ''));
            if ($rule['mode'] !== 'digest' || $recipient === '') {
                continue;
            }
            $run = $store->runLogs()->where('tool', $tool)->where('status', '!=', 'error')->where('created_at', '>=', today())->latest()->first();
            $rows = (int) ($run?->rows_found ?? 0);
            if ($run && $rows >= $rule['threshold'] && ($rows > 0 || $rule['include_zero'])) {
                $byRecipient[$recipient][] = ['tool' => $tool, 'rows' => $rows];
            }
        }
        foreach ($byRecipient as $email => $sections) {
            Notification::route('mail', $email)->notify(new ReportDigestNotification($store->label, $sections));
        }
    });
})->purpose('Queue daily report email digests');

Schedule::command('activitylog:clean')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('auth:clear-resets')->hourly();
Schedule::call(function (): void {
    app(CheckOperationalAlerts::class)->handle();
})
    ->name('health:operational-alerts')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('health:schedule-check-heartbeat')->everyMinute();
Schedule::command('health:queue-check-heartbeat')->everyMinute();
Schedule::command('health:check')->everyMinute()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [HealthCheckResultHistoryItem::class]])->dailyAt('02:45');
Schedule::command('backup:run', ['--only-db'])->dailyAt('01:15')->withoutOverlapping();
Schedule::command('backup:run')->weeklyOn(0, '01:45')->withoutOverlapping();
Schedule::command('backup:monitor')->hourlyAt(20)->withoutOverlapping();
Schedule::command('backup:clean')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('backup:verify-latest')->weeklyOn(0, '03:00')->withoutOverlapping()->onOneServer();
Schedule::command('reports:email-digest')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('reports:queue-scheduled-audits')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('operations:detect-anomalies')->hourlyAt(10)->withoutOverlapping()->onOneServer();
Schedule::command('operations:detect-delivery-exceptions')->dailyAt('07:10')->withoutOverlapping()->onOneServer();

if (config('queue.default') === 'redis') {
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
}
