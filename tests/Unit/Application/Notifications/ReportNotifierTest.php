<?php

namespace Tests\Unit\Application\Notifications;

use App\Application\Notifications\ReportNotifier;
use App\Models\Store;
use App\Notifications\AuditFinishedNotification;
use App\Notifications\Channels\DiscordWebhookChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReportNotifierTest extends TestCase
{
    use RefreshDatabase;

    private const array COUNTS = ['found' => 10, 'skipped' => 0, 'ignored' => 0, 'shipstation_total' => 10];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config([
            'services.slack.notifications.webhook_url' => 'https://hooks.slack.test/abc',
            'services.discord.notifications.webhook_url' => 'https://discord.test/api/webhooks/abc',
        ]);
    }

    public function test_audit_notifications_respect_minimum_missing_and_zero_settings(): void
    {
        $store = Store::factory()->create([
            'slack_rules' => ['audit_enabled' => true, 'audit_min_missing' => 2, 'include_zero_audit' => false, 'mentions' => 'U12345678'],
            'discord_rules' => ['audit_enabled' => true, 'audit_min_missing' => 0, 'include_zero_audit' => true],
        ]);
        $notifier = app(ReportNotifier::class);

        $notifier->auditFinished($store, '2026-10-01 → 2026-10-03', [], self::COUNTS, 1.5);
        $notifier->auditFinished($store, '2026-10-01 → 2026-10-03', [['name' => '#1001', 'total' => 20.0]], self::COUNTS, 1.5);
        $notifier->auditFinished($store, '2026-10-01 → 2026-10-03', [['name' => '#1001', 'total' => 20.0], ['name' => '#1002', 'total' => 30.0]], self::COUNTS, 1.5);

        Notification::assertSentOnDemandTimes(AuditFinishedNotification::class, 3);
        Notification::assertSentOnDemand(AuditFinishedNotification::class, fn (AuditFinishedNotification $notification, array $channels): bool => $notification->missing === 2
            && $notification->mentions === 'U12345678'
            && $channels === [SlackWebhookChannel::class, DiscordWebhookChannel::class]);
        Notification::assertSentOnDemand(AuditFinishedNotification::class, fn (AuditFinishedNotification $notification, array $channels): bool => $notification->missing === 1 && $channels === [DiscordWebhookChannel::class]);
        Notification::assertSentOnDemand(AuditFinishedNotification::class, fn (AuditFinishedNotification $notification, array $channels): bool => $notification->missing === 0 && $channels === [DiscordWebhookChannel::class]);
    }

    public function test_nothing_is_sent_without_a_configured_webhook_or_when_disabled(): void
    {
        config(['services.slack.notifications.webhook_url' => ' ']);
        $store = Store::factory()->create(['discord_rules' => ['audit_enabled' => false]]);

        app(ReportNotifier::class)->auditFinished($store, 'period', [['name' => '#1001', 'total' => 20.0]], self::COUNTS, 1.0);

        Notification::assertNothingSent();
    }
}
