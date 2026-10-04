<?php

namespace App\Notifications;

use App\Notifications\Concerns\FormatsSlackMentions;
use App\Notifications\Concerns\RoutesToChatChannels;
use Illuminate\Notifications\Slack\SlackMessage;

class AuditFinishedNotification extends QueuedNotification
{
    use FormatsSlackMentions, RoutesToChatChannels;

    public function __construct(
        public string $store,
        public int $missing,
        public string $period,
        public string $mentions = '',
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

    public function toSlack(object $notifiable): SlackMessage
    {
        $lines = $this->missingOrderLines();

        return (new SlackMessage)
            ->text($this->slackMentionsPrefix().$this->summary())
            ->headerBlock($this->title())
            ->contextBlock(function ($block): void {
                $block->text(__('Period: :period', ['period' => $this->period]));
            })
            ->sectionBlock(function ($block) use ($lines): void {
                foreach ($this->counts() as $label => $value) {
                    $block->field("*{$label}:* {$value}")->markdown();
                }
                if ($lines !== []) {
                    $block->field(implode("\n", $lines))->markdown();
                }
            });
    }

    /** @return array{content: string, embeds: list<array<string, mixed>>} */
    public function toDiscord(object $notifiable): array
    {
        $fields = [];
        foreach ($this->counts() as $label => $value) {
            $fields[] = ['name' => $label, 'value' => $value, 'inline' => true];
        }

        return [
            'content' => $this->summary(),
            'embeds' => [[
                'title' => $this->title(),
                'color' => $this->missing > 0 ? 0xE74C3C : 0x2ECC71,
                'fields' => $fields,
                'description' => implode("\n", $this->missingOrderLines()),
            ]],
        ];
    }

    private function summary(): string
    {
        return __(':store: Run Audit found :missing missing orders (:period).', ['store' => $this->store, 'missing' => $this->missing, 'period' => $this->period]);
    }

    private function title(): string
    {
        return __(':store: Run Audit', ['store' => $this->store]);
    }

    /** @return array<string, string> */
    private function counts(): array
    {
        return [
            __('Missing') => (string) $this->missing,
            __('Matched') => (string) $this->found,
            __('Skipped') => (string) $this->skipped,
            __('Ignored') => (string) $this->ignored,
            __('ShipStation total') => (string) $this->shipstationTotal,
            __('Duration') => "{$this->durationSeconds}s",
        ];
    }

    /** @return list<string> */
    private function missingOrderLines(): array
    {
        $lines = array_map(fn (array $order): string => "{$order['name']} - \${$order['total']}", array_slice($this->missingOrders, 0, 10));
        if (count($this->missingOrders) > 10) {
            $lines[] = __('and :count more', ['count' => count($this->missingOrders) - 10]);
        }

        return $lines;
    }
}
