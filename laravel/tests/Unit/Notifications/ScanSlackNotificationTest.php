<?php

namespace Tests\Unit\Notifications;

use App\Notifications\ScanSlackNotification;
use Tests\TestCase;

class ScanSlackNotificationTest extends TestCase
{
    public function test_it_includes_scan_summary_fields(): void
    {
        $notification = new ScanSlackNotification(
            store: 'Test Store',
            tool: 'scan_addresses',
            rows: 5,
            durationSeconds: 1.5,
        );

        $json = json_encode($notification->toSlack((object) [])->toArray());

        $this->assertStringContainsString('Test Store', $json);
        $this->assertStringContainsString('scan_addresses', $json);
        $this->assertStringContainsString('5', $json);
        $this->assertStringContainsString('1.5', $json);
    }
}
