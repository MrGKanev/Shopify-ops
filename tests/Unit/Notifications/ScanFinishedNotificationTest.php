<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\ScanFinishedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Tests\TestCase;

class ScanFinishedNotificationTest extends TestCase
{
    public function test_it_is_queued_and_delivered_only_to_routed_chat_channels(): void
    {
        $notification = new ScanFinishedNotification('Store A', 'scan_sla', 3);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('notifications', $notification->queue);
        $this->assertSame([SlackWebhookChannel::class], $notification->via((new AnonymousNotifiable)->route('slack', 'https://hooks.slack.test/a')));
        $this->assertSame([DiscordWebhookChannel::class], $notification->via((new AnonymousNotifiable)->route('discord', 'https://discord.test/a')));
        $this->assertSame([SlackWebhookChannel::class, DiscordWebhookChannel::class], $notification->via((new AnonymousNotifiable)->route('slack', 'https://hooks.slack.test/a')->route('discord', 'https://discord.test/a')));
        $this->assertSame([], $notification->via((object) []));
    }

    public function test_slack_message_formats_mentions(): void
    {
        $notification = new ScanFinishedNotification('Store A', 'scan_sla', 3, 'U012ABC3DE S024XYZ9FG');

        $this->assertStringContainsString('<@U012ABC3DE> <@S024XYZ9FG> Store A: scan_sla found 3 rows.', (string) json_encode($notification->toSlack((object) [])->toArray()));
    }

    public function test_slack_message_omits_the_mention_prefix_when_no_mentions_are_configured(): void
    {
        $notification = new ScanFinishedNotification('Store A', 'scan_sla', 3);

        $this->assertSame('Store A: scan_sla found 3 rows.', $notification->toSlack((object) [])->toArray()['text']);
    }

    public function test_slack_message_includes_scan_summary_fields(): void
    {
        $notification = new ScanFinishedNotification(store: 'Test Store', tool: 'scan_addresses', rows: 5, durationSeconds: 1.5);

        $json = json_encode($notification->toSlack((object) [])->toArray());

        $this->assertStringContainsString('Test Store', $json);
        $this->assertStringContainsString('scan_addresses', $json);
        $this->assertStringContainsString('5', $json);
        $this->assertStringContainsString('1.5', $json);
    }

    public function test_discord_message_has_no_mentions_and_is_colour_coded(): void
    {
        $notification = new ScanFinishedNotification('Store A', 'scan_sla', 3, 'U012ABC3DE');

        $payload = $notification->toDiscord((object) []);

        $this->assertSame('Store A: scan_sla found 3 rows.', $payload['content']);
        $this->assertSame(0xE74C3C, $payload['embeds'][0]['color']);
        $this->assertStringContainsString('1.5', (string) json_encode((new ScanFinishedNotification(store: 'S', tool: 't', rows: 0, durationSeconds: 1.5))->toDiscord((object) [])));
        $this->assertSame(0x2ECC71, (new ScanFinishedNotification('S', 't', 0))->toDiscord((object) [])['embeds'][0]['color']);
    }
}
