<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;

class OperationalAlertSlackNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $category, public string $summary)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return [SlackWebhookChannel::class];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)->text("Operational alert: {$this->category} failed ({$this->summary}).");
    }
}
