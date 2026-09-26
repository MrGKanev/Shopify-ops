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
            ->text($prefix.__(':store: :tool found :rows rows.', ['store' => $this->store, 'tool' => $this->tool, 'rows' => $this->rows]))
            ->headerBlock(__(':store: :tool', ['store' => $this->store, 'tool' => $this->tool]))
            ->sectionBlock(function ($block): void {
                $block->field('*'.__('Rows').":* {$this->rows}")->markdown();
                $block->field('*'.__('Duration').":* {$this->durationSeconds}s")->markdown();
            });
    }
}
