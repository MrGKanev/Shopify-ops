<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordWebhookChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AuditDiscordNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        $this->onQueue('notifications');
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
            $lines[] = 'and '.(count($this->missingOrders) - 10).' more';
        }

        return [
            'content' => "{$this->store}: Run Audit found {$this->missing} missing orders ({$this->period}).",
            'embeds' => [[
                'title' => "{$this->store} — Run Audit",
                'color' => $this->missing > 0 ? 0xE74C3C : 0x2ECC71,
                'fields' => [
                    ['name' => 'Missing', 'value' => (string) $this->missing, 'inline' => true],
                    ['name' => 'Matched', 'value' => (string) $this->found, 'inline' => true],
                    ['name' => 'Skipped', 'value' => (string) $this->skipped, 'inline' => true],
                    ['name' => 'Ignored', 'value' => (string) $this->ignored, 'inline' => true],
                    ['name' => 'ShipStation total', 'value' => (string) $this->shipstationTotal, 'inline' => true],
                    ['name' => 'Duration', 'value' => "{$this->durationSeconds}s", 'inline' => true],
                ],
                'description' => implode("\n", $lines),
            ]],
        ];
    }
}
