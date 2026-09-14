<?php

namespace Tests\Unit\Listeners;

use App\Listeners\AlertOnOperationalFailure;
use App\Notifications\AuditSlackNotification;
use App\Notifications\OperationalAlertDiscordNotification;
use App\Notifications\OperationalAlertSlackNotification;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Notifications\AnonymousNotifiable;
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
            OperationalAlertSlackNotification::class,
            fn (OperationalAlertSlackNotification $n): bool => $n->category === 'App\\Jobs\\RunAuditJob' && $n->summary === 'RuntimeException',
        );
        Notification::assertSentOnDemand(OperationalAlertDiscordNotification::class);
    }

    public function test_sends_an_alert_when_a_notification_delivery_fails(): void
    {
        config(['services.slack.notifications.webhook_url' => 'https://hooks.slack.com/services/test']);
        Notification::fake();
        $notifiable = (new AnonymousNotifiable)->route('slack', 'https://hooks.slack.com/services/secret');
        $notification = new AuditSlackNotification('Acme', 3, '2026-09-01 → 2026-09-07');

        (new AlertOnOperationalFailure)->handle(new NotificationFailed($notifiable, $notification, 'slack', ['exception' => new RuntimeException('webhook rejected')]));

        Notification::assertSentOnDemand(
            OperationalAlertSlackNotification::class,
            fn (OperationalAlertSlackNotification $n): bool => $n->category === 'AuditSlackNotification' && $n->summary === 'RuntimeException',
        );
    }

    public function test_does_not_alert_on_its_own_alert_notification_failing(): void
    {
        config(['services.slack.notifications.webhook_url' => 'https://hooks.slack.com/services/test']);
        Notification::fake();
        $notifiable = (new AnonymousNotifiable)->route('slack', 'https://hooks.slack.com/services/test');
        $notification = new OperationalAlertSlackNotification('App\\Jobs\\RunAuditJob', 'RuntimeException');

        (new AlertOnOperationalFailure)->handle(new NotificationFailed($notifiable, $notification, 'slack', ['exception' => new RuntimeException('still down')]));

        Notification::assertNothingSent();
    }
}
