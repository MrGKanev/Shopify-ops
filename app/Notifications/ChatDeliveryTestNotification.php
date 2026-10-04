<?php

namespace App\Notifications;

use App\Notifications\Concerns\RoutesToChatChannels;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Confirms a Slack or Discord webhook works. It carries no store credentials or order data.
 */
class ChatDeliveryTestNotification extends QueuedNotification
{
    use RoutesToChatChannels;

    public function __construct(
        public readonly string $applicationName,
        public readonly string $sentAt,
    ) {
        parent::__construct();
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->text(__(':app successfully connected to Slack at :time. No store credentials or order data are included.', ['app' => $this->applicationName, 'time' => $this->sentAt]))
            ->headerBlock(__(':app Slack delivery test', ['app' => $this->applicationName]))
            ->unfurlLinks(false)
            ->unfurlMedia(false);
    }

    /** @return array{content: string} */
    public function toDiscord(object $notifiable): array
    {
        return ['content' => __(':app successfully connected to Discord at :time. No store credentials or order data are included.', ['app' => $this->applicationName, 'time' => $this->sentAt])];
    }
}
