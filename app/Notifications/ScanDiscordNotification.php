<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordWebhookChannel;

class ScanDiscordNotification extends QueuedNotification
{
    public function __construct(
        public string $store,
        public string $tool,
        public int $rows,
        public float $durationSeconds = 0.0,
    ) {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return [DiscordWebhookChannel::class];
    }

    /** @return array{content: string, embeds: list<array<string, mixed>>} */
    public function toDiscord(object $notifiable): array
    {
        return [
            'content' => __(':store: :tool found :rows rows.', ['store' => $this->store, 'tool' => $this->tool, 'rows' => $this->rows]),
            'embeds' => [[
                'title' => __(':store: :tool', ['store' => $this->store, 'tool' => $this->tool]),
                'color' => $this->rows > 0 ? 0xE74C3C : 0x2ECC71,
                'fields' => [
                    ['name' => __('Rows'), 'value' => (string) $this->rows, 'inline' => true],
                    ['name' => __('Duration'), 'value' => "{$this->durationSeconds}s", 'inline' => true],
                ],
            ]],
        ];
    }
}
