<?php

namespace App\Application\Operations;

use App\Jobs\ProcessShipStationEvent;
use App\Models\ShipStationEvent;
use App\Models\Store;
use Illuminate\Support\Carbon;

class ReceiveShipStationEvent
{
    /** @param array<string, mixed> $payload */
    public function handle(Store $store, string $topic, string $identity, array $payload, ?Carbon $availableAt = null): ShipStationEvent
    {
        $generation = hash('sha256', (string) $store->shipstation_monitoring_token);
        $event = ShipStationEvent::firstOrCreate(
            ['store_id' => $store->id, 'event_key' => hash('sha256', $generation.'|'.$topic.'|'.$identity)],
            ['generation' => $generation, 'topic' => $topic, 'payload' => $payload, 'available_at' => $availableAt ?? now()],
        );
        if ($event->wasRecentlyCreated) {
            ProcessShipStationEvent::dispatch($event->id)->delay($event->available_at);
        }

        return $event;
    }
}
