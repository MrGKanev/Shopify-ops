<?php

namespace Tests\Unit;

use App\Notifications\ScanSlackNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;

class ScanSlackNotificationTest extends TestCase
{
    public function test_it_is_queueable_and_formats_mentions(): void
    {
        $notification = new ScanSlackNotification('Store A', 'scan_sla', 3, 'U012ABC3DE S024XYZ9FG');

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('notifications', $notification->queue);
        $this->assertStringContainsString('<@U012ABC3DE> <@S024XYZ9FG> Store A: scan_sla found 3 rows.', (string) json_encode($notification->toSlack((object) [])->toArray()));
    }

    public function test_it_omits_the_mention_prefix_when_no_mentions_are_configured(): void
    {
        $notification = new ScanSlackNotification('Store A', 'scan_sla', 3);

        $this->assertSame('Store A: scan_sla found 3 rows.', $notification->toSlack((object) [])->toArray()['text']);
    }
}
