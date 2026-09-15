<?php

namespace Tests\Feature\Console\Commands;

use App\Jobs\RunAuditJob;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueScheduledAuditsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_queues_only_due_configured_stores_once_per_day(): void
    {
        $this->travelTo('2026-09-15 08:30:00');
        Queue::fake([RunAuditJob::class]);
        $due = Store::factory()->create(['scheduled_audit_enabled' => true, 'scheduled_audit_time' => '08:30']);
        Store::factory()->create(['scheduled_audit_enabled' => true, 'scheduled_audit_time' => '09:30']);
        Store::factory()->create(['scheduled_audit_enabled' => false, 'scheduled_audit_time' => '08:30']);

        $this->artisan('reports:queue-scheduled-audits')->expectsOutput('Queued 1 scheduled audit(s).')->assertSuccessful();
        $this->artisan('reports:queue-scheduled-audits')->assertSuccessful();

        Queue::assertPushed(RunAuditJob::class, 1, fn (RunAuditJob $job): bool => $job->storeId === $due->getKey() && $job->startDate === '2026-08-16' && $job->endDate === '2026-09-15');
        $this->assertDatabaseCount('audit_jobs', 1);
    }

    public function test_it_skips_a_store_without_complete_integration_credentials(): void
    {
        $this->travelTo('2026-09-15 08:30:00');
        Queue::fake([RunAuditJob::class]);
        Store::factory()->create(['scheduled_audit_enabled' => true, 'scheduled_audit_time' => '08:30', 'shipstation_api_secret' => null]);

        $this->artisan('reports:queue-scheduled-audits')->expectsOutput('Queued 0 scheduled audit(s).')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('audit_jobs', 0);
    }
}
