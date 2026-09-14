<?php

namespace Tests\Unit\Notifications;

use App\Notifications\ReportEmailNotification;
use Tests\TestCase;

class ReportEmailNotificationTest extends TestCase
{
    public function test_attaches_a_safe_csv_when_attachment_rows_are_present(): void
    {
        $notification = new ReportEmailNotification('Acme', 'run_audit', 2, '2026-09-01', '2026-09-07', ['Order', 'Total'], [['#1001', '=2+5'], ['#1002', '19.99']]);

        $mail = $notification->toMail((object) []);

        $this->assertCount(1, $mail->rawAttachments);
        $this->assertSame('run_audit-2026-09-01-to-2026-09-07.csv', $mail->rawAttachments[0]['name']);
        $this->assertSame('text/csv', $mail->rawAttachments[0]['options']['mime']);
        $this->assertStringContainsString("Order,Total\r\n", $mail->rawAttachments[0]['data']);
        $this->assertStringContainsString("#1001,'=2+5\r\n", $mail->rawAttachments[0]['data']);
    }

    public function test_sends_no_attachment_when_there_are_no_attachment_rows(): void
    {
        $notification = new ReportEmailNotification('Acme', 'same_ip', 0, '2026-09-01', '2026-09-07');

        $mail = $notification->toMail((object) []);

        $this->assertCount(0, $mail->rawAttachments);
    }
}
