<?php

namespace App\Notifications;

use App\Notifications\Concerns\FormatsSlackMentions;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Notifications\Slack\SlackMessage;

class AuditSlackNotification extends QueuedNotification
{
    use FormatsSlackMentions;

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

    public function via(object $notifiable): array
    {
        return [SlackWebhookChannel::class];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $prefix = $this->slackMentionsPrefix();
        $shown = array_slice($this->missingOrders, 0, 10);
        $lines = array_map(fn (array $order): string => "{$order['name']} - \${$order['total']}", $shown);
        if (count($this->missingOrders) > 10) {
            $lines[] = __('and :count more', ['count' => count($this->missingOrders) - 10]);
        }

        return (new SlackMessage)
            ->text($prefix.__(':store: Run Audit found :missing missing orders (:period).', ['store' => $this->store, 'missing' => $this->missing, 'period' => $this->period]))
            ->headerBlock(__(':store: Run Audit', ['store' => $this->store]))
            ->contextBlock(function ($block): void {
                $block->text(__('Period: :period', ['period' => $this->period]));
            })
            ->sectionBlock(function ($block) use ($lines): void {
                $block->field('*'.__('Missing').":* {$this->missing}")->markdown();
                $block->field('*'.__('Matched').":* {$this->found}")->markdown();
                $block->field('*'.__('Skipped').":* {$this->skipped}")->markdown();
                $block->field('*'.__('Ignored').":* {$this->ignored}")->markdown();
                $block->field('*'.__('ShipStation total').":* {$this->shipstationTotal}")->markdown();
                $block->field('*'.__('Duration').":* {$this->durationSeconds}s")->markdown();
                if ($lines !== []) {
                    $block->field(implode("\n", $lines))->markdown();
                }
            });
    }
}
