<?php

namespace App\Listeners;

use App\Notifications\OperationalAlertDiscordNotification;
use App\Notifications\OperationalAlertSlackNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Notification;
use Throwable;

class AlertOnOperationalFailure
{
    public function handle(JobFailed|NotificationFailed $event): void
    {
        if ($event instanceof NotificationFailed && $this->isOwnAlert($event->notification)) {
            return;
        }

        [$category, $summary] = $event instanceof JobFailed
            ? [$event->job->resolveName(), $this->exceptionClass($event->exception)]
            : [class_basename($event->notification), $this->exceptionClass($event->data['exception'] ?? null)];

        $slackUrl = trim((string) config('services.slack.notifications.webhook_url'));
        if ($slackUrl !== '') {
            Notification::route('slack', $slackUrl)->notify(new OperationalAlertSlackNotification($category, $summary));
        }

        $discordUrl = trim((string) config('services.discord.notifications.webhook_url'));
        if ($discordUrl !== '') {
            Notification::route('discord', $discordUrl)->notify(new OperationalAlertDiscordNotification($category, $summary));
        }
    }

    private function isOwnAlert(object $notification): bool
    {
        return $notification instanceof OperationalAlertSlackNotification || $notification instanceof OperationalAlertDiscordNotification;
    }

    private function exceptionClass(mixed $exception): string
    {
        return $exception instanceof Throwable ? $exception::class : 'unknown';
    }
}
