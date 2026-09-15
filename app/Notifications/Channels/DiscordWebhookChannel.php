<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DiscordWebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $url = $notifiable->routeNotificationFor('discord', $notification);

        if (! $url) {
            return;
        }

        $response = Http::asJson()->post($url, $notification->toDiscord($notifiable));

        if ($response->failed()) {
            throw new RuntimeException("Discord webhook error {$response->status()}: {$response->body()}");
        }
    }
}
