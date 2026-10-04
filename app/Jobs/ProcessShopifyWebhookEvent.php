<?php

namespace App\Jobs;

use App\Application\Operations\DetectOrderWebhookIssues;
use App\Application\Operations\RaiseOperationalIssue;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessShopifyWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 180];

    public int $timeout = 30;

    public function __construct(public int $eventId) {}

    public function handle(RaiseOperationalIssue $issues, DetectOrderWebhookIssues $orderIssues): void
    {
        $event = WebhookEvent::query()->with('store')->findOrFail($this->eventId);
        if ($event->status === 'processed') {
            return;
        }
        $orderIssues->handle($event);
        $issue = $this->issueAttributes($event);

        if ($issue !== null) {
            $issues->handle($event->store, [
                ...$issue,
                'source_tool' => 'shopify_webhook',
                'fingerprint' => RaiseOperationalIssue::fingerprint('webhook', "{$event->topic}|{$event->subject_id}"),
                'reference' => $event->subject_id,
                'payload' => ['webhook_event_id' => $event->getKey(), 'topic' => $event->topic],
            ], reopenIgnored: false);
        }

        $event->update(['status' => 'processed', 'processed_at' => now(), 'error_category' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        WebhookEvent::whereKey($this->eventId)->update([
            'status' => 'failed',
            'processed_at' => now(),
            'error_category' => $exception === null ? 'unknown' : $exception::class,
        ]);
    }

    /** @return array{title:string,priority:string}|null */
    private function issueAttributes(WebhookEvent $event): ?array
    {
        return match ($event->topic) {
            'refunds/create' => ['title' => "Shopify refund created for {$event->subject_id}", 'priority' => 'high'],
            'disputes/create' => ['title' => "Shopify dispute opened for {$event->subject_id}", 'priority' => 'urgent'],
            default => null,
        };
    }
}
