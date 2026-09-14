<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReadinessEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_readiness_reports_database_and_queue_configuration(): void
    {
        config()->set('queue.default', 'sync');
        Cache::put('health:checks:schedule:latestHeartbeatAt', now()->timestamp);

        $this->getJson('/ready')->assertOk()->assertExactJson([
            'status' => 'ready',
            'checks' => ['database' => true, 'cache' => true, 'queue' => true, 'worker' => true, 'scheduler' => true],
        ]);
    }

    public function test_readiness_returns_service_unavailable_for_missing_queue_configuration(): void
    {
        config()->set('queue.default', '');

        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('status', 'not_ready')->assertJsonPath('checks.queue', false);
    }

    public function test_readiness_requires_a_fresh_worker_heartbeat_for_async_queues(): void
    {
        config()->set('queue.default', 'database');
        Cache::put('health:checks:queue:latestHeartbeatAt.default', now()->subMinutes(10)->timestamp);
        Cache::put('health:checks:schedule:latestHeartbeatAt', now()->timestamp);

        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('checks.worker', false);

        Cache::put('health:checks:queue:latestHeartbeatAt.default', now()->timestamp);
        $this->getJson('/ready')->assertOk()->assertJsonPath('checks.worker', true);
    }

    public function test_readiness_requires_a_fresh_scheduler_heartbeat(): void
    {
        config()->set('queue.default', 'sync');
        Cache::put('health:checks:schedule:latestHeartbeatAt', now()->subMinutes(6)->timestamp);

        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('checks.scheduler', false);
    }
}
