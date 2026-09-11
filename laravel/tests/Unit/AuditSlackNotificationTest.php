<?php

namespace Tests\Unit;

use App\Notifications\AuditSlackNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;

class AuditSlackNotificationTest extends TestCase
{
    public function test_it_is_queueable_and_formats_mentions(): void
    {
        $notification = new AuditSlackNotification('Store A', 3, '2026-09-01 → 2026-09-10', 'U012ABC3DE S024XYZ9FG');

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('notifications', $notification->queue);
        $this->assertStringContainsString('<@U012ABC3DE> <@S024XYZ9FG> Store A', (string) json_encode($notification->toSlack((object) [])->toArray()));
    }

    public function test_it_omits_the_mention_prefix_when_no_mentions_are_configured(): void
    {
        $notification = new AuditSlackNotification('Store A', 3, '2026-09-01 → 2026-09-10');

        $this->assertSame('Store A: Run Audit found 3 missing orders (2026-09-01 → 2026-09-10).', $notification->toSlack((object) [])->toArray()['text']);
    }
}
