<?php

namespace App\Notifications;

use App\Notifications\Concerns\FormatsSlackMentions;
use App\Notifications\Concerns\RoutesToChatChannels;
use Illuminate\Notifications\Slack\SlackMessage;

class ScanFinishedNotification extends QueuedNotification
{
    use FormatsSlackMentions, RoutesToChatChannels;

    public function __construct(
        public string $store,
        public string $tool,
        public int $rows,
        public string $mentions = '',
        public float $durationSeconds = 0.0,
    ) {
        parent::__construct();
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->text($this->slackMentionsPrefix().$this->summary())
            ->headerBlock($this->title())
            ->sectionBlock(function ($block): void {
                $block->field('*'.__('Rows').":* {$this->rows}")->markdown();
                $block->field('*'.__('Duration').":* {$this->durationSeconds}s")->markdown();
            });
    }

    /** @return array{content: string, embeds: list<array<string, mixed>>} */
    public function toDiscord(object $notifiable): array
    {
        return [
            'content' => $this->summary(),
            'embeds' => [[
                'title' => $this->title(),
                'color' => $this->rows > 0 ? 0xE74C3C : 0x2ECC71,
                'fields' => [
                    ['name' => __('Rows'), 'value' => (string) $this->rows, 'inline' => true],
                    ['name' => __('Duration'), 'value' => "{$this->durationSeconds}s", 'inline' => true],
                ],
            ]],
        ];
    }

    private function summary(): string
    {
        return __(':store: :tool found :rows rows.', ['store' => $this->store, 'tool' => $this->tool, 'rows' => $this->rows]);
    }

    private function title(): string
    {
        return __(':store: :tool', ['store' => $this->store, 'tool' => $this->tool]);
    }
}
