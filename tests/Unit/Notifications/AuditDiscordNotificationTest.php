<?php

namespace Tests\Unit\Notifications;

use App\Notifications\AuditDiscordNotification;
use Tests\TestCase;

class AuditDiscordNotificationTest extends TestCase
{
    public function test_it_includes_the_full_summary_with_color_coding(): void
    {
        $notification = new AuditDiscordNotification(
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
        $notification = new AuditDiscordNotification(store: 'S', missing: 0, period: 'p');

        $payload = $notification->toDiscord((object) []);

        $this->assertSame(0x2ECC71, $payload['embeds'][0]['color']);
    }
}
