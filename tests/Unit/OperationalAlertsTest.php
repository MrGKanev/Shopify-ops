<?php

namespace Tests\Unit;

use App\Application\Health\CheckOperationalAlerts;
use App\Listeners\AlertOnOperationalFailure;
use App\Models\RunLog;
use App\Models\Store;
use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\OperationalAlertNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Horizon\WaitTimeCalculator;
use Mockery;
use Tests\TestCase;

class OperationalAlertsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_alerts_once_for_queue_latency_scheduler_absence_and_repeated_api_failures(): void
    {
        $this->travelTo('2026-09-13 12:00:00');
        config([
            'queue.default' => 'database',
            'services.slack.notifications.webhook_url' => 'https://hooks.slack.com/services/test',
            'services.discord.notifications.webhook_url' => 'https://discord.com/api/webhooks/test',
        ]);
        Notification::fake();
        Cache::put('health:checks:schedule:latestHeartbeatAt', now()->subMinutes(6)->timestamp);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subSeconds(301)->timestamp, 'created_at' => now()->subSeconds(301)->timestamp]);
        $store = Store::factory()->create();
        foreach (range(1, 3) as $_) {
            RunLog::create(['store_id' => $store->getKey(), 'tool' => 'refund_tracker', 'status' => 'error']);
        }
        $check = new CheckOperationalAlerts(new AlertOnOperationalFailure, Mockery::mock(WaitTimeCalculator::class));

        $check->handle();
        $check->handle();

        Notification::assertSentOnDemandTimes(OperationalAlertNotification::class, 3);
        Notification::assertSentOnDemand(OperationalAlertNotification::class, fn (OperationalAlertNotification $alert, array $channels): bool => $alert->category === 'queue_latency' && $channels === [SlackWebhookChannel::class, DiscordWebhookChannel::class]);
        Notification::assertSentOnDemand(OperationalAlertNotification::class, fn (OperationalAlertNotification $alert): bool => $alert->category === 'scheduler_absent');
        Notification::assertSentOnDemand(OperationalAlertNotification::class, fn (OperationalAlertNotification $alert): bool => $alert->category === 'api_failures:refund_tracker');
    }
}
