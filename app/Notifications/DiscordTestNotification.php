<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordWebhookChannel;

class DiscordTestNotification extends QueuedNotification
{
    public function __construct(
        public readonly string $applicationName,
        public readonly string $sentAt,
    ) {
        parent::__construct();
    }

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [DiscordWebhookChannel::class];
    }

    /** @return array{content: string} */
    public function toDiscord(object $notifiable): array
    {
        return ['content' => $this->applicationName.' successfully connected to Discord at '.$this->sentAt.'. No store credentials or order data are included.'];
    }
}
