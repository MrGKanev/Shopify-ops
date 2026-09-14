<?php

namespace Tests\Unit\Notifications;

use App\Notifications\ScanDiscordNotification;
use Tests\TestCase;

class ScanDiscordNotificationTest extends TestCase
{
    public function test_it_includes_scan_summary_fields(): void
    {
        $notification = new ScanDiscordNotification(store: 'Test Store', tool: 'scan_addresses', rows: 5, durationSeconds: 1.5);

        $json = json_encode($notification->toDiscord((object) []));

        $this->assertStringContainsString('Test Store', $json);
        $this->assertStringContainsString('scan_addresses', $json);
        $this->assertStringContainsString('1.5', $json);
    }
}
