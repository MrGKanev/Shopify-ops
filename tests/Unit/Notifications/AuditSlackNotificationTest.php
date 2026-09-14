<?php

namespace Tests\Unit\Notifications;

use App\Notifications\AuditSlackNotification;
use Tests\TestCase;

class AuditSlackNotificationTest extends TestCase
{
    public function test_it_includes_the_full_summary(): void
    {
        $notification = new AuditSlackNotification(
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

    public function test_it_truncates_the_order_list_to_ten_with_a_tail_count(): void
    {
        $orders = array_map(fn (int $i): array => ['name' => "#{$i}", 'total' => 10.0], range(1, 13));

        $notification = new AuditSlackNotification(store: 'S', missing: 13, period: 'p', missingOrders: $orders);

        $json = json_encode($notification->toSlack((object) [])->toArray());

        $this->assertStringContainsString('#10', $json);
        $this->assertStringNotContainsString('#11', $json);
        $this->assertStringContainsString('and 3 more', $json);
    }
}
