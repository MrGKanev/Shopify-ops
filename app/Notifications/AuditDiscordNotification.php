<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordWebhookChannel;

class AuditDiscordNotification extends QueuedNotification
{
    public function __construct(
        public string $store,
        public int $missing,
        public string $period,
        public int $found = 0,
        public int $skipped = 0,
        public int $ignored = 0,
        public int $shipstationTotal = 0,
        public float $durationSeconds = 0.0,
        /** @var list<array{name: string, total: float}> */
        public array $missingOrders = [],
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
        $shown = array_slice($this->missingOrders, 0, 10);
        $lines = array_map(fn (array $order): string => "{$order['name']} - \${$order['total']}", $shown);
        if (count($this->missingOrders) > 10) {
            $lines[] = __('and :count more', ['count' => count($this->missingOrders) - 10]);
        }

        return [
            'content' => __(':store: Run Audit found :missing missing orders (:period).', ['store' => $this->store, 'missing' => $this->missing, 'period' => $this->period]),
            'embeds' => [[
                'title' => __(':store: Run Audit', ['store' => $this->store]),
                'color' => $this->missing > 0 ? 0xE74C3C : 0x2ECC71,
                'fields' => [
                    ['name' => __('Missing'), 'value' => (string) $this->missing, 'inline' => true],
                    ['name' => __('Matched'), 'value' => (string) $this->found, 'inline' => true],
                    ['name' => __('Skipped'), 'value' => (string) $this->skipped, 'inline' => true],
                    ['name' => __('Ignored'), 'value' => (string) $this->ignored, 'inline' => true],
                    ['name' => __('ShipStation total'), 'value' => (string) $this->shipstationTotal, 'inline' => true],
                    ['name' => __('Duration'), 'value' => "{$this->durationSeconds}s", 'inline' => true],
                ],
                'description' => implode("\n", $lines),
            ]],
        ];
    }
}
