<?php

namespace App\Notifications;

use App\Notifications\Concerns\FormatsSlackMentions;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Notifications\Slack\SlackMessage;

class ScanSlackNotification extends QueuedNotification
{
    use FormatsSlackMentions;

    public function __construct(
        public string $store,
        public string $tool,
        public int $rows,
        public string $mentions = '',
        public float $durationSeconds = 0.0,
    ) {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return [SlackWebhookChannel::class];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $prefix = $this->slackMentionsPrefix();

        return (new SlackMessage)
            ->text("{$prefix}{$this->store}: {$this->tool} found {$this->rows} rows.")
            ->headerBlock("{$this->store} — {$this->tool}")
            ->sectionBlock(function ($block): void {
                $block->field("*Rows:* {$this->rows}")->markdown();
                $block->field("*Duration:* {$this->durationSeconds}s")->markdown();
            });
    }
}
