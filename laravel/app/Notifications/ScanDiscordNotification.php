<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordWebhookChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ScanDiscordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $store, public string $tool, public int $rows)
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
        return ['content' => "{$this->store}: {$this->tool} found {$this->rows} rows."];
    }
}
