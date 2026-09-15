<?php

namespace App\Jobs;

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

    public function handle(): void
    {
        $event = WebhookEvent::query()->with('store')->findOrFail($this->eventId);
        $issue = $this->issueAttributes($event);

        if ($issue !== null) {
            $fingerprint = hash('sha256', "webhook|{$event->topic}|{$event->subject_id}");
            $operationalIssue = $event->store->operationalIssues()->firstOrNew(['fingerprint' => $fingerprint]);
            $operationalIssue->fill([
                ...$issue,
                'source_tool' => 'shopify_webhook',
                'reference' => $event->subject_id,
                'status' => $operationalIssue->exists && $operationalIssue->status === 'resolved' ? 'open' : ($operationalIssue->status ?: 'open'),
                'occurrences' => $operationalIssue->exists ? $operationalIssue->occurrences + 1 : 1,
                'first_seen_at' => $operationalIssue->first_seen_at ?? now(),
                'last_seen_at' => now(),
                'resolved_at' => null,
                'payload' => ['webhook_event_id' => $event->getKey(), 'topic' => $event->topic],
            ])->save();
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
