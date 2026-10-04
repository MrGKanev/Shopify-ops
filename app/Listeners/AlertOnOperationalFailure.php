<?php

namespace App\Listeners;

use App\Notifications\OperationalAlertNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Queue\Events\JobFailed;
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

        $this->send($category, $summary);
    }

    public function send(string $category, string $summary): void
    {
        $notifiable = new AnonymousNotifiable;
        foreach (['slack', 'discord'] as $channel) {
            $url = trim((string) config("services.{$channel}.notifications.webhook_url"));
            if ($url !== '') {
                $notifiable->route($channel, $url);
            }
        }

        if ($notifiable->routes !== []) {
            $notifiable->notify(new OperationalAlertNotification($category, $summary));
        }
    }

    private function isOwnAlert(object $notification): bool
    {
        return $notification instanceof OperationalAlertNotification;
    }

    private function exceptionClass(mixed $exception): string
    {
        return $exception instanceof Throwable ? $exception::class : 'unknown';
    }
}
