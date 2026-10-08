<?php

namespace App\Jobs;

use App\Application\Operations\ManageShipStationMonitoring;
use App\Application\Operations\ReceiveShipStationEvent;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\IssueStatus;
use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ReconcileShipStationMonitoring implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $storeId) {}

    public function uniqueId(): string
    {
        return (string) $this->storeId;
    }

    public function handle(ManageShipStationMonitoring $monitoring, ShipStationClientFactory $clients, ReceiveShipStationEvent $events): void
    {
        Cache::lock('shipstation-monitoring:'.$this->storeId, 300)->block(5, function () use ($monitoring, $clients, $events): void {
            $store = Store::find($this->storeId);
            if ($store === null) {
                return;
            }
            $monitoring->handle($store);
            if (! $store->shipstation_monitoring_enabled || ! $store->shipStationMonitoringCanQueue()) {
                return;
            }
            $since = ($store->shipstation_monitoring_checked_at ?? $store->shipstation_monitoring_started_at)->copy()->subMinutes(30);
            $scanStarted = now();
            $client = $clients->forStore($store);
            if ($client === null) {
                return;
            }
            $shipments = $client->recentMonitoringShipments((int) $store->store_number, $since->setTimezone('America/Los_Angeles')->format('Y-m-d H:i:s'));
            foreach ($shipments as $shipment) {
                if (! is_numeric($shipment['shipmentId'] ?? null) || ! is_numeric($shipment['orderId'] ?? null)) {
                    throw new \UnexpectedValueException('ShipStation returned an invalid shipment identity.');
                }
                $events->handle($store, 'shipment_check', (string) $shipment['shipmentId'], ['order_id' => (int) $shipment['orderId'], 'shipment_id' => (int) $shipment['shipmentId']], now()->addMinutes(15));
            }
            $store->forceFill(['shipstation_monitoring_checked_at' => $scanStarted])->save();
            $activeShipmentIds = $store->operationalIssues()->where('source_tool', 'shipstation_sync')->whereIn('status', IssueStatus::active())->get()->map(fn ($issue): int => (int) ($issue->payload['shipment_id'] ?? 0))->all();
            foreach ($store->shipStationEvents()->where('topic', 'shipment_check')->where('status', 'processed')->where('generation', hash('sha256', (string) $store->shipstation_monitoring_token))->get() as $previous) {
                if (in_array((int) ($previous->payload['shipment_id'] ?? 0), $activeShipmentIds, true)) {
                    $previous->update(['status' => 'received', 'available_at' => now()]);
                }
            }
            foreach ($store->shipStationEvents()->whereIn('status', ['received', 'failed'])->where('available_at', '<=', now())->limit(100)->get() as $event) {
                ProcessShipStationEvent::dispatch($event->id);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        Store::whereKey($this->storeId)->update(['shipstation_monitoring_status' => 'failed']);
    }
}
