<?php

namespace App\Notifications;

use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Notifications\Slack\SlackMessage;

class OperationalAlertSlackNotification extends QueuedNotification
{
    public function __construct(public string $category, public string $summary)
    {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return [SlackWebhookChannel::class];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)->text(__('Operational alert: :category failed (:summary).', ['category' => $this->category, 'summary' => $this->summary]));
    }
}
