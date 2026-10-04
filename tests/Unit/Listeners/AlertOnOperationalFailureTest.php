<?php

namespace Tests\Unit\Listeners;

use App\Listeners\AlertOnOperationalFailure;
use App\Notifications\AuditFinishedNotification;
use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\OperationalAlertNotification;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AlertOnOperationalFailureTest extends TestCase
{
    public function test_is_registered_for_job_failed_and_notification_failed_events(): void
    {
        Event::fake();

        Event::assertListening(JobFailed::class, AlertOnOperationalFailure::class);
        Event::assertListening(NotificationFailed::class, AlertOnOperationalFailure::class);
    }

    public function test_sends_a_slack_and_discord_alert_when_a_queued_job_fails(): void
    {
        config(['services.slack.notifications.webhook_url' => 'https://hooks.slack.com/services/test', 'services.discord.notifications.webhook_url' => 'https://discord.com/api/webhooks/test']);
        Notification::fake();
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\RunAuditJob');

        (new AlertOnOperationalFailure)->handle(new JobFailed('database', $job, new RuntimeException('db unreachable')));

        Notification::assertSentOnDemand(
            OperationalAlertNotification::class,
            fn (OperationalAlertNotification $n, array $channels): bool => $n->category === 'App\\Jobs\\RunAuditJob' && $n->summary === 'RuntimeException' && $channels === [SlackWebhookChannel::class, DiscordWebhookChannel::class],
        );
    }

    public function test_sends_an_alert_when_a_notification_delivery_fails(): void
    {
        config(['services.slack.notifications.webhook_url' => 'https://hooks.slack.com/services/test']);
        Notification::fake();
        $notifiable = (new AnonymousNotifiable)->route('slack', 'https://hooks.slack.com/services/secret');
        $notification = new AuditFinishedNotification('Acme', 3, '2026-09-01 → 2026-09-07');

        (new AlertOnOperationalFailure)->handle(new NotificationFailed($notifiable, $notification, 'slack', ['exception' => new RuntimeException('webhook rejected')]));

        Notification::assertSentOnDemand(
            OperationalAlertNotification::class,
            fn (OperationalAlertNotification $n): bool => $n->category === 'AuditFinishedNotification' && $n->summary === 'RuntimeException',
        );
    }

    public function test_does_not_alert_on_its_own_alert_notification_failing(): void
    {
        config(['services.slack.notifications.webhook_url' => 'https://hooks.slack.com/services/test']);
        Notification::fake();
        $notifiable = (new AnonymousNotifiable)->route('slack', 'https://hooks.slack.com/services/test');
        $notification = new OperationalAlertNotification('App\\Jobs\\RunAuditJob', 'RuntimeException');

        (new AlertOnOperationalFailure)->handle(new NotificationFailed($notifiable, $notification, 'slack', ['exception' => new RuntimeException('still down')]));

        Notification::assertNothingSent();
    }
}
