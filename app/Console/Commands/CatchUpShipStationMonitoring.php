<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileShipStationMonitoring;
use App\Models\Store;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shipstation:catch-up')]
#[Description('Queue subscription reconciliation and missed shipment checks only for opted-in stores.')]
class CatchUpShipStationMonitoring extends Command
{
    public function handle(): int
    {
        $queued = 0;
        foreach (Store::where('shipstation_monitoring_enabled', true)->lazyById(100) as $store) {
            if ($store->shipStationMonitoringCanQueue()) {
                ReconcileShipStationMonitoring::dispatch($store->id);
                $queued++;
            }
        }
        $this->info("Queued {$queued} opted-in store(s).");

        return self::SUCCESS;
    }
}
