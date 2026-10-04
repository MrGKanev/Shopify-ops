<?php

namespace App\Notifications;

use App\Notifications\Concerns\RoutesToChatChannels;
use Illuminate\Notifications\Slack\SlackMessage;

class OperationalAlertNotification extends QueuedNotification
{
    use RoutesToChatChannels;

    public function __construct(public string $category, public string $summary)
    {
        parent::__construct();
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)->text($this->message());
    }

    /** @return array{content: string} */
    public function toDiscord(object $notifiable): array
    {
        return ['content' => $this->message()];
    }

    private function message(): string
    {
        return __('Operational alert: :category failed (:summary).', ['category' => $this->category, 'summary' => $this->summary]);
    }
}
