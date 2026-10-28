<?php

namespace Tests\Feature\Console\Commands;

use App\Jobs\ReconcileShipStationMonitoring;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CatchUpShipStationMonitoringTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_opted_in_stores_are_queued_when_a_real_queue_is_configured(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
        Queue::fake();
        $optedIn = Store::factory()->create(['shipstation_monitoring_enabled' => true]);
        Store::factory()->create(['shipstation_monitoring_enabled' => false]);

        $this->artisan('shipstation:catch-up')->expectsOutput('Queued 1 opted-in store(s).')->assertExitCode(0);

        Queue::assertPushed(ReconcileShipStationMonitoring::class, 1);
        Queue::assertPushed(ReconcileShipStationMonitoring::class, fn (ReconcileShipStationMonitoring $job): bool => $job->storeId === $optedIn->id);
    }

    public function test_nothing_is_queued_on_the_sync_queue_because_it_would_run_inline(): void
    {
        config(['queue.default' => 'sync']);
        Queue::fake();
        Store::factory()->create(['shipstation_monitoring_enabled' => true]);

        $this->artisan('shipstation:catch-up')->expectsOutput('Queued 0 opted-in store(s).')->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
