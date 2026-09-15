<?php

namespace Tests\Unit\Listeners;

use App\Listeners\LogNotificationDelivery;
use App\Models\NotificationDelivery;
use App\Notifications\AuditSlackNotification;
use App\Notifications\SlackTestNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class LogNotificationDeliveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_is_registered_for_both_sent_and_failed_notification_events(): void
    {
        Event::fake();

        Event::assertListening(NotificationSent::class, LogNotificationDelivery::class);
        Event::assertListening(NotificationFailed::class, LogNotificationDelivery::class);
    }

    public function test_records_a_sent_mail_delivery_with_recipient(): void
    {
        $notifiable = (new AnonymousNotifiable)->route('mail', 'ops@example.com');
        $notification = new SlackTestNotification('ShipStation Checker', '2026-09-11 12:00:00');

        (new LogNotificationDelivery)->handle(new NotificationSent($notifiable, $notification, 'mail'));

        $this->assertDatabaseHas('notification_deliveries', [
            'channel' => 'mail',
            'notification_type' => 'SlackTestNotification',
            'recipient' => 'ops@example.com',
            'status' => 'sent',
            'error_category' => null,
        ]);
    }

    public function test_does_not_record_the_webhook_url_as_recipient_for_non_mail_channels(): void
    {
        $notifiable = (new AnonymousNotifiable)->route('slack', 'https://hooks.slack.com/services/secret-token');
        $notification = new AuditSlackNotification('Acme', 3, '2026-09-01 → 2026-09-07');

        (new LogNotificationDelivery)->handle(new NotificationSent($notifiable, $notification, 'slack'));

        $this->assertDatabaseHas('notification_deliveries', [
            'channel' => 'slack',
            'notification_type' => 'AuditSlackNotification',
            'store_label' => 'Acme',
            'recipient' => null,
            'status' => 'sent',
        ]);
        $this->assertSame(0, NotificationDelivery::where('recipient', 'like', '%secret-token%')->count());
    }

    public function test_records_a_failed_delivery_with_an_error_category_and_no_message(): void
    {
        $notifiable = (new AnonymousNotifiable)->route('slack', 'https://hooks.slack.com/services/secret-token');
        $notification = new AuditSlackNotification('Acme', 3, '2026-09-01 → 2026-09-07');
        $exception = new RuntimeException('webhook token secret-token rejected');

        (new LogNotificationDelivery)->handle(new NotificationFailed($notifiable, $notification, 'slack', ['exception' => $exception]));

        $this->assertDatabaseHas('notification_deliveries', [
            'channel' => 'slack',
            'notification_type' => 'AuditSlackNotification',
            'status' => 'failed',
            'error_category' => RuntimeException::class,
        ]);
        $this->assertDatabaseMissing('notification_deliveries', ['error_category' => 'webhook token secret-token rejected']);
    }
}
