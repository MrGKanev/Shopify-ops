<?php

namespace App\Notifications\Concerns;

use App\Notifications\Channels\DiscordWebhookChannel;
use Illuminate\Notifications\Channels\SlackWebhookChannel;

/**
 * Deliver to Slack and/or Discord depending on which webhook routes the notifiable has.
 */
trait RoutesToChatChannels
{
    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return [];
        }

        return array_values(array_filter([
            $notifiable->routeNotificationFor('slack', $this) ? SlackWebhookChannel::class : null,
            $notifiable->routeNotificationFor('discord', $this) ? DiscordWebhookChannel::class : null,
        ]));
    }
}
