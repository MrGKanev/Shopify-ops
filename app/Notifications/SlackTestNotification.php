<?php

namespace App\Notifications;

use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Notifications\Slack\SlackMessage;

class SlackTestNotification extends QueuedNotification
{
    public function __construct(
        public readonly string $applicationName,
        public readonly string $sentAt,
    ) {
        parent::__construct();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [SlackWebhookChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->text(__(':app successfully connected to Slack at :time. No store credentials or order data are included.', ['app' => $this->applicationName, 'time' => $this->sentAt]))
            ->headerBlock(__(':app Slack delivery test', ['app' => $this->applicationName]))
            ->unfurlLinks(false)
            ->unfurlMedia(false);
    }
}
