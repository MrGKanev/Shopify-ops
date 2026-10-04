<?php

namespace Tests\Unit\Notifications;

use App\Notifications\AuditFinishedNotification;
use App\Notifications\Channels\DiscordWebhookChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Tests\TestCase;

class AuditFinishedNotificationTest extends TestCase
{
    public function test_slack_message_formats_mentions(): void
    {
        $notification = new AuditFinishedNotification('Store A', 3, '2026-09-01 → 2026-09-10', 'U012ABC3DE S024XYZ9FG');

        $this->assertStringContainsString('<@U012ABC3DE> <@S024XYZ9FG> Store A', (string) json_encode($notification->toSlack((object) [])->toArray()));
    }

    public function test_slack_message_omits_the_mention_prefix_when_no_mentions_are_configured(): void
    {
        $notification = new AuditFinishedNotification('Store A', 3, '2026-09-01 → 2026-09-10');

        $this->assertSame('Store A: Run Audit found 3 missing orders (2026-09-01 → 2026-09-10).', $notification->toSlack((object) [])->toArray()['text']);
    }

    public function test_slack_message_includes_the_full_summary(): void
    {
        $notification = new AuditFinishedNotification(
            store: 'Test Store',
            missing: 2,
            period: '2026-01-01 → 2026-01-31',
            found: 10,
            skipped: 1,
            ignored: 0,
            shipstationTotal: 13,
            durationSeconds: 4.2,
            missingOrders: [
                ['name' => '#1001', 'total' => 49.99],
                ['name' => '#1002', 'total' => 120.0],
            ],
        );

        $payload = $notification->toSlack((object) [])->toArray();
        $json = json_encode($payload);

        $this->assertStringContainsString('#1001', $json);
        $this->assertStringContainsString('49.99', $json);
        $this->assertStringContainsString('#1002', $json);
        $this->assertStringContainsString('13', $json);
        $this->assertStringContainsString('4.2', $json);
        $this->assertStringContainsString('Test Store', $json);
    }

    public function test_slack_message_truncates_the_order_list_to_ten_with_a_tail_count(): void
    {
        $orders = array_map(fn (int $i): array => ['name' => "#{$i}", 'total' => 10.0], range(1, 13));

        $notification = new AuditFinishedNotification(store: 'S', missing: 13, period: 'p', missingOrders: $orders);

        $json = json_encode($notification->toSlack((object) [])->toArray());

        $this->assertStringContainsString('#10', $json);
        $this->assertStringNotContainsString('#11', $json);
        $this->assertStringContainsString('and 3 more', $json);
    }

    public function test_it_is_queued_for_the_routed_chat_channels_and_formats_the_discord_message(): void
    {
        $notification = new AuditFinishedNotification('Store A', 3, '2026-09-01 → 2026-09-10', 'U012ABC3DE');

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('notifications', $notification->queue);
        $this->assertSame([DiscordWebhookChannel::class], $notification->via((new AnonymousNotifiable)->route('discord', 'https://discord.test/a')));
        $this->assertSame([SlackWebhookChannel::class, DiscordWebhookChannel::class], $notification->via((new AnonymousNotifiable)->route('slack', 'https://hooks.slack.test/a')->route('discord', 'https://discord.test/a')));
        $payload = $notification->toDiscord((object) []);
        $this->assertSame('Store A: Run Audit found 3 missing orders (2026-09-01 → 2026-09-10).', $payload['content']);
        $this->assertSame(0xE74C3C, $payload['embeds'][0]['color']);
    }

    public function test_discord_message_includes_the_full_summary_with_color_coding(): void
    {
        $notification = new AuditFinishedNotification(
            store: 'Test Store',
            missing: 2,
            period: '2026-01-01 → 2026-01-31',
            found: 10,
            skipped: 1,
            ignored: 0,
            shipstationTotal: 13,
            durationSeconds: 4.2,
            missingOrders: [['name' => '#1001', 'total' => 49.99]],
        );

        $payload = $notification->toDiscord((object) []);
        $json = json_encode($payload);

        $this->assertStringContainsString('#1001', $json);
        $this->assertStringContainsString('49.99', $json);
        $this->assertSame(0xE74C3C, $payload['embeds'][0]['color']);
    }

    public function test_zero_missing_is_colored_green(): void
    {
        $notification = new AuditFinishedNotification(store: 'S', missing: 0, period: 'p');

        $payload = $notification->toDiscord((object) []);

        $this->assertSame(0x2ECC71, $payload['embeds'][0]['color']);
    }
}
