<?php

namespace Tests\Unit;

use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\ScanDiscordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

class ScanDiscordNotificationTest extends TestCase
{
    public function test_it_is_queueable_and_formats_the_message(): void
    {
        $notification = new ScanDiscordNotification('Store A', 'scan_sla', 3);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('notifications', $notification->queue);
        $this->assertSame([DiscordWebhookChannel::class], $notification->via((object) []));
        $payload = $notification->toDiscord((object) []);
        $this->assertSame('Store A: scan_sla found 3 rows.', $payload['content']);
        $this->assertSame(0xE74C3C, $payload['embeds'][0]['color']);
    }
}
