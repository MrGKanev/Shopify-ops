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
            'content' => "{$this->store}: {$this->tool} found {$this->rows} rows.",
            'embeds' => [[
                'title' => "{$this->store} — {$this->tool}",
                'color' => $this->rows > 0 ? 0xE74C3C : 0x2ECC71,
                'fields' => [
                    ['name' => 'Rows', 'value' => (string) $this->rows, 'inline' => true],
                    ['name' => 'Duration', 'value' => "{$this->durationSeconds}s", 'inline' => true],
                ],
            ]],
        ];
    }
}
