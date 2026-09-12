<?php

namespace Tests\Unit;

use App\Notifications\AuditDiscordNotification;
use App\Notifications\Channels\DiscordWebhookChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;

class AuditDiscordNotificationTest extends TestCase
{
    public function test_it_is_queueable_and_formats_the_message(): void
    {
        $notification = new AuditDiscordNotification('Store A', 3, '2026-09-01 → 2026-09-10');

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('notifications', $notification->queue);
        $this->assertSame([DiscordWebhookChannel::class], $notification->via((object) []));
        $this->assertSame(['content' => 'Store A: Run Audit found 3 missing orders (2026-09-01 → 2026-09-10).'], $notification->toDiscord((object) []));
    }
}
