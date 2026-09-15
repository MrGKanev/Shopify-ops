<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordWebhookChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class OperationalAlertDiscordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $category, public string $summary)
    {
        $this->onQueue('notifications');
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
