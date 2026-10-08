<?php

namespace App\Jobs;

use App\Application\Operations\CheckShipStationShipment;
use App\Application\Operations\ReceiveShipStationEvent;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\ShipStationEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessShipStationEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $eventId) {}

    public function handle(ShipStationClientFactory $clients, ReceiveShipStationEvent $events, CheckShipStationShipment $checks): void
    {
        $event = ShipStationEvent::with('store')->find($this->eventId);
        if ($event === null) {
            return;
        }
        Cache::lock('shipstation-monitoring:'.$event->store_id, 300)->block(5, function () use ($event, $clients, $events, $checks): void {
            $event->refresh();
            $store = $event->store->fresh();
            if (in_array($event->status, ['processed', 'skipped'], true)) {
                return;
            }
            if (! $store->shipstation_monitoring_enabled || ! hash_equals($event->generation, hash('sha256', (string) $store->shipstation_monitoring_token))) {
                $event->update(['status' => 'skipped', 'processed_at' => now()]);

                return;
            }
            if (! $store->shipStationMonitoringCanQueue() || $event->available_at->isFuture()) {
                return;
            }
            $client = $clients->forStore($store);
            if ($client === null) {
                throw new \UnexpectedValueException('ShipStation credentials are required.');
            }
            if ($event->topic === 'shipment_check') {
                $checks->handle($store, $client, (int) $event->payload['order_id'], (int) $event->payload['shipment_id']);
            } else {
                $resources = $client->webhookResource($event->payload['resource_url'], $event->topic, (int) $store->store_number);
                foreach ($resources as $resource) {
                    if ($event->topic === 'ORDER_NOTIFY') {
                        if ((int) ($resource['advancedOptions']['storeId'] ?? 0) !== (int) $store->store_number || ($resource['orderStatus'] ?? '') !== 'shipped') {
                            continue;
                        }
                        $resourcesForOrder = $client->getOrderShipments((string) $resource['orderNumber'], true);
                    } else {
                        $resourcesForOrder = [$resource];
                    }
                    foreach ($resourcesForOrder as $shipment) {
                        if (! is_numeric($shipment['shipmentId'] ?? null) || ! is_numeric($shipment['orderId'] ?? null)) {
                            throw new \UnexpectedValueException('ShipStation returned an invalid shipment identity.');
                        }
                        $events->handle($store, 'shipment_check', (string) $shipment['shipmentId'], ['order_id' => (int) $shipment['orderId'], 'shipment_id' => (int) $shipment['shipmentId']], now()->addMinutes(15));
                    }
                }
            }
            $event->update(['status' => 'processed', 'processed_at' => now(), 'error_category' => null]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        ShipStationEvent::whereKey($this->eventId)->where('status', '!=', 'skipped')->update(['status' => 'failed', 'error_category' => $exception === null ? 'unknown' : $exception::class]);
    }
}
