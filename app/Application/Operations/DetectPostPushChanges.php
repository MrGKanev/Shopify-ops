<?php

namespace App\Application\Operations;

use App\Application\Orders\LoadOrderForRemediation;
use App\Domain\Orders\ShipStationRelevantFingerprint;
use App\Models\WebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DetectPostPushChanges
{
    public function __construct(private readonly LoadOrderForRemediation $orders, private readonly RaiseOperationalIssue $issues) {}

    public function handle(WebhookEvent $event): void
    {
        if ($event->topic !== 'orders/updated' || $event->subject_id === null || $event->store->missingShipStationCredentials()) {
            return;
        }
        $push = $event->store->pushLogs()->where('shopify_id', $event->subject_id)->where('status', 'success')->latest('pushed_at')->latest('id')->first();
        if ($push === null || $push->shipstation_order_id === null) {
            return;
        }
        try {
            if (empty($event->payload['updated_at']) || Carbon::parse($event->payload['updated_at'])->lte($push->pushed_at)) {
                return;
            }
        } catch (\Exception) {
            return;
        }
        Cache::lock('order-remediation:'.$event->store_id.':'.$event->subject_id, 300)->block(5, function () use ($event, $push): void {
            $order = $this->orders->shopify($event->store, $push->order_number);
            if ((string) $order['id'] !== $event->subject_id) {
                return;
            }
            $actual = $this->orders->shipStation($event->store, $order);
            if ($actual === null) {
                return;
            }
            $expected = $this->orders->client($event->store)->buildOrderPayload($order);
            $diff = ShipStationRelevantFingerprint::diff($expected, $actual);
            $fingerprint = RaiseOperationalIssue::fingerprint('order_changed_after_push', $event->subject_id);
            if ($diff === []) {
                $issue = $event->store->operationalIssues()->where('fingerprint', $fingerprint)->first();
                if ($issue !== null) {
                    $this->issues->resolve($issue);
                }

                return;
            }
            $this->issues->handle($event->store, [
                'source_tool' => 'order_changed_after_push', 'fingerprint' => $fingerprint,
                'reference' => $event->subject_id, 'title' => 'Shopify shipping data differs from ShipStation after push',
                'priority' => ($actual['orderStatus'] ?? '') === 'shipped' ? 'urgent' : 'high',
                'payload' => [
                    'order_number' => $order['name'] ?? $push->order_number,
                    'webhook_event_id' => $event->id, 'shipstation_order_id' => $actual['orderId'],
                    'shipstation_status' => $actual['orderStatus'] ?? '', 'diff' => $diff,
                    'pushed_at' => $push->pushed_at->toIso8601String(),
                    'expected_hash' => ShipStationRelevantFingerprint::hash($expected),
                ],
            ], reopenIgnored: false, countOncePerDay: true);
        });
    }
}
