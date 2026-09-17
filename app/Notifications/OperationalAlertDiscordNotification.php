<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordWebhookChannel;

class OperationalAlertDiscordNotification extends QueuedNotification
{
    public function __construct(public string $category, public string $summary)
    {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return [DiscordWebhookChannel::class];
    }

    /** @return array{content: string} */
    public function toDiscord(object $notifiable): array
    {
        return ['content' => "Operational alert: {$this->category} failed ({$this->summary})."];
    }
}
