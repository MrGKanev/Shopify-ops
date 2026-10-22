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
        $store = $event->store;
        $subjectId = $event->subject_id;
        if ($event->topic !== 'orders/updated' || $subjectId === null || $store === null || $store->missingShipStationCredentials()) {
            return;
        }
        $push = $store->pushLogs()->where('shopify_id', $subjectId)->where('status', 'success')->latest('pushed_at')->latest('id')->first();
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
        Cache::lock('order-remediation:'.$store_id.':'.$subjectId, 300)->block(5, function () use ($event, $push, $store, $subjectId): void {
            $order = $this->orders->shopify($store, $push->order_number);
            if ((string) $order['id'] !== $subjectId) {
                return;
            }
            $actual = $this->orders->shipStation($store, $order);
            if ($actual === null) {
                return;
            }
            $expected = $this->orders->client($store)->buildOrderPayload($order);
            $diff = ShipStationRelevantFingerprint::diff($expected, $actual);
            $fingerprint = RaiseOperationalIssue::fingerprint('order_changed_after_push', $subjectId);
            if ($diff === []) {
                $issue = $store->operationalIssues()->where('fingerprint', $fingerprint)->first();
                if ($issue !== null) {
                    $this->issues->resolve($issue);
                }

                return;
            }
            $this->issues->handle($store, [
                'source_tool' => 'order_changed_after_push', 'fingerprint' => $fingerprint,
                'reference' => $subjectId, 'title' => 'Shopify shipping data differs from ShipStation after push',
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
