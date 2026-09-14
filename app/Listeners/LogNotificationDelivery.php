<?php

namespace App\Listeners;

use App\Models\NotificationDelivery;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

class LogNotificationDelivery
{
    public function handle(NotificationSent|NotificationFailed $event): void
    {
        $exception = $event instanceof NotificationFailed ? ($event->data['exception'] ?? null) : null;

        NotificationDelivery::create([
            'channel' => $event->channel,
            'notification_type' => class_basename($event->notification),
            'store_label' => $this->storeLabel($event->notification),
            'recipient' => $this->recipient($event),
            'status' => $event instanceof NotificationFailed ? 'failed' : 'sent',
            'error_category' => $exception instanceof Throwable ? $exception::class : null,
        ]);
    }

    private function storeLabel(object $notification): ?string
    {
        return property_exists($notification, 'store') && is_string($notification->store) ? $notification->store : null;
    }

    private function recipient(NotificationSent|NotificationFailed $event): ?string
    {
        if ($event->channel !== 'mail' || ! method_exists($event->notifiable, 'routeNotificationFor')) {
            return null;
        }

        $route = $event->notifiable->routeNotificationFor('mail');

        return is_string($route) ? $route : null;
    }
}
