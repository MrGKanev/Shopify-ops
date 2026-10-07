<?php

namespace App\Application\Operations;

use App\Domain\Reports\DuplicateAddressAnalyzer;
use App\Models\WebhookEvent;
use Illuminate\Support\Carbon;

class DetectOrderWebhookIssues
{
    public function __construct(private readonly RaiseOperationalIssue $issues, private readonly DetectPostPushChanges $postPushChanges, private readonly DuplicateAddressAnalyzer $addresses) {}

    public function handle(WebhookEvent $event): void
    {
        if (! str_starts_with($event->topic, 'orders/') || $event->subject_id === null || $event->payload === []) {
            return;
        }

        $this->postPushChanges->handle($event);
        $this->detectRepeatedAddress($event);
    }

    private function detectRepeatedAddress(WebhookEvent $event): void
    {
        $addressKey = $this->addresses->addressKey($event->payload);
        if ($addressKey === null || ! $this->needsFulfillment($event->payload)) {
            return;
        }

        $orders = [];
        $seen = [];
        foreach ($event->store->webhookEvents()->where('topic', 'like', 'orders/%')
            ->where('occurred_at', '>=', now()->subDays(7))->whereNotNull('subject_id')
            ->orderByDesc('occurred_at')->orderByDesc('id')->lazy(200) as $candidate) {
            $order = $candidate->payload;
            $updatedAt = $this->date($order['updated_at'] ?? null) ?? $candidate->occurred_at;
            if (isset($seen[$candidate->subject_id]) && $updatedAt->lte($seen[$candidate->subject_id])) {
                continue;
            }
            $seen[$candidate->subject_id] = $updatedAt;
            unset($orders[$candidate->subject_id]);
            if ($order === [] || ! $this->needsFulfillment($order) || $this->addresses->addressKey($order) !== $addressKey) {
                continue;
            }
            $orders[$candidate->subject_id] = $order;
        }

        if (! isset($orders[$event->subject_id])) {
            return;
        }
        $names = array_unique(array_filter(array_map(fn (array $order): string => $this->normalize($this->addresses->recipientName($order)), $orders)));
        $emails = array_unique(array_filter(array_map(fn (array $order): string => is_scalar($order['email'] ?? null) ? mb_strtolower(trim((string) $order['email'])) : '', $orders)));
        if (count($orders) < 3 && (count($orders) < 2 || (count($names) < 2 && count($emails) < 2))) {
            return;
        }

        $this->issues->handle($event->store, [
            'source_tool' => 'repeated_shipping_address',
            'fingerprint' => RaiseOperationalIssue::fingerprint('repeated_shipping_address', $addressKey),
            'reference' => $event->subject_id,
            'title' => 'Recent unfulfilled orders share a shipping address',
            'priority' => 'normal',
            'payload' => [
                'order_number' => $event->payload['name'] ?? $event->subject_id,
                'order_ids' => array_map('strval', array_keys($orders)),
                'order_numbers' => array_values(array_map(fn (array $order): string => (string) ($order['name'] ?? $order['id'] ?? ''), $orders)),
                'order_count' => count($orders),
                'different_names' => count($names),
                'different_emails' => count($emails),
                'review_note' => 'Shared addresses may belong to families, offices or forwarding services. Different recipients are not proof of fraud.',
                'window_days' => 7,
                'warning_only' => true,
                'review_context' => $this->addresses->reviewContext(array_values($orders)),
            ],
        ], reopenIgnored: false, countOncePerDay: true);
    }

    /** @param array<string, mixed> $order */
    private function needsFulfillment(array $order): bool
    {
        $createdAt = $this->date($order['created_at'] ?? null);

        return $createdAt !== null && $createdAt->between(now()->subDays(7), now())
            && empty($order['cancelled_at']) && ($order['fulfillment_status'] ?? null) !== 'fulfilled';
    }

    private function normalize(mixed $value): string
    {
        return is_scalar($value) ? mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $value) ?? '') : '';
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }
}
