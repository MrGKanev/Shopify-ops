<?php

namespace App\Application\Operations;

use App\Models\WebhookEvent;
use Illuminate\Support\Carbon;

class DetectOrderWebhookIssues
{
    public function __construct(private readonly RaiseOperationalIssue $issues) {}

    public function handle(WebhookEvent $event): void
    {
        if (! str_starts_with($event->topic, 'orders/') || $event->subject_id === null || $event->payload === []) {
            return;
        }

        $this->detectChangesAfterPush($event);
        $this->detectRepeatedAddress($event);
    }

    private function detectChangesAfterPush(WebhookEvent $event): void
    {
        if ($event->topic !== 'orders/updated') {
            return;
        }

        $updatedAt = $this->date($event->payload['updated_at'] ?? null);
        $push = $event->store->pushLogs()->where('shopify_id', $event->subject_id)
            ->where('status', 'success')->latest('pushed_at')->first();

        if ($push === null || $updatedAt === null || $updatedAt->lte($push->pushed_at)) {
            return;
        }

        $this->issues->handle($event->store, [
            'source_tool' => 'order_changed_after_push',
            'fingerprint' => RaiseOperationalIssue::fingerprint('order_changed_after_push', $event->subject_id),
            'reference' => $event->subject_id,
            'title' => 'Shopify order changed after ShipStation push',
            'priority' => 'normal',
            'payload' => [
                'order_number' => $event->payload['name'] ?? $push->order_number,
                'webhook_event_id' => $event->getKey(),
                'pushed_at' => $push->pushed_at->toIso8601String(),
                'updated_at' => $updatedAt->toIso8601String(),
                'shipstation_order_id' => $push->shipstation_order_id,
            ],
        ], reopenIgnored: false, countOncePerDay: true);
    }

    private function detectRepeatedAddress(WebhookEvent $event): void
    {
        $addressKey = $this->addressKey($event->payload);
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
            if ($order === [] || ! $this->needsFulfillment($order) || $this->addressKey($order) !== $addressKey) {
                continue;
            }
            $orders[$candidate->subject_id] = $order;
        }

        if (! isset($orders[$event->subject_id])) {
            return;
        }
        $names = array_unique(array_filter(array_map(fn (array $order): string => $this->normalize(
            $order['shipping_address']['name'] ?? trim(($order['shipping_address']['first_name'] ?? '').' '.($order['shipping_address']['last_name'] ?? '')),
        ), $orders)));
        if (count($orders) < 3 && (count($orders) < 2 || count($names) < 2)) {
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
                'window_days' => 7,
                'warning_only' => true,
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

    /** @param array<string, mixed> $order */
    private function addressKey(array $order): ?string
    {
        $address = $order['shipping_address'] ?? null;
        if (! is_array($address)) {
            return null;
        }
        $parts = array_map(fn (string $field): string => $this->normalize($address[$field] ?? ''),
            ['address1', 'address2', 'city', 'province_code', 'zip', 'country_code']);
        if ($parts[0] === '' || $parts[2] === '' || $parts[5] === '') {
            return null;
        }

        return implode('|', $parts);
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
