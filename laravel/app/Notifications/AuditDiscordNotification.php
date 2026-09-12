<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordWebhookChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AuditDiscordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $store, public int $missing, public string $period)
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
        return ['content' => "{$this->store}: Run Audit found {$this->missing} missing orders ({$this->period})."];
    }
}
