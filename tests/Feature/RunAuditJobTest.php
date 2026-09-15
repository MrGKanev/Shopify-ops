<?php

namespace Tests\Feature;

use App\Application\Reports\AuditResult;
use App\Application\Reports\RunAudit;
use App\Jobs\RunAuditJob;
use App\Models\AuditJob;
use App\Models\Store;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RunAuditJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_handle_resolves_the_store_and_delegates_to_run_audit(): void
    {
        $store = Store::factory()->create();
        $audit = Mockery::mock(RunAudit::class);
        $audit->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-06-01', '2026-06-30')
            ->andReturn(new AuditResult('2026-06-01', '2026-06-30', [], 0, 0, 0, 0, 0, false));

        (new RunAuditJob($store->getKey(), '2026-06-01', '2026-06-30'))->handle($audit);
    }

    public function test_handle_throws_when_the_store_no_longer_exists(): void
    {
        $audit = Mockery::mock(RunAudit::class);
        $audit->shouldNotReceive('handle');

        $this->expectException(ModelNotFoundException::class);

        (new RunAuditJob(999999, '2026-06-01', '2026-06-30'))->handle($audit);
    }

    public function test_it_does_not_queue_a_duplicate_for_the_same_store_and_range_while_one_is_pending(): void
    {
        Queue::fake();
        $store = Store::factory()->create();

        RunAuditJob::dispatch($store->getKey(), '2026-06-01', '2026-06-30');
        RunAuditJob::dispatch($store->getKey(), '2026-06-01', '2026-06-30');

        Queue::assertPushed(RunAuditJob::class, 1);
    }

    public function test_it_still_queues_a_different_range_for_the_same_store(): void
    {
        Queue::fake();
        $store = Store::factory()->create();

        RunAuditJob::dispatch($store->getKey(), '2026-06-01', '2026-06-30');
        RunAuditJob::dispatch($store->getKey(), '2026-07-01', '2026-07-31');

        Queue::assertPushed(RunAuditJob::class, 2);
    }

    public function test_it_still_queues_the_same_range_for_a_different_store(): void
    {
        Queue::fake();
        $stores = Store::factory()->count(2)->create();

        RunAuditJob::dispatch($stores[0]->getKey(), '2026-06-01', '2026-06-30');
        RunAuditJob::dispatch($stores[1]->getKey(), '2026-06-01', '2026-06-30');

        Queue::assertPushed(RunAuditJob::class, 2);
    }

    public function test_it_declares_an_explicit_timeout_retry_count_and_backoff(): void
    {
        $job = new RunAuditJob(1, '2026-06-01', '2026-06-30');

        $this->assertSame(300, $job->timeout);
        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->backoff);
    }

    public function test_it_records_running_completed_and_terminal_failure_states(): void
    {
        $store = Store::factory()->create();
        $auditJob = AuditJob::create(['store_id' => $store->getKey(), 'start_date' => '2026-06-01', 'end_date' => '2026-06-30']);
        $audit = Mockery::mock(RunAudit::class);
        $audit->shouldReceive('handle')->once()->andReturn(new AuditResult('2026-06-01', '2026-06-30', [], 0, 0, 0, 0, 0, false));
        $job = new RunAuditJob($store->getKey(), '2026-06-01', '2026-06-30', $auditJob->getKey());

        $job->handle($audit);

        $this->assertSame('completed', $auditJob->fresh()->status);
        $this->assertNotNull($auditJob->fresh()->started_at);
        $this->assertNotNull($auditJob->fresh()->finished_at);

        $job->failed(new RuntimeException('secret-token'));
        $this->assertSame('failed', $auditJob->fresh()->status);
        $this->assertSame(RuntimeException::class, $auditJob->fresh()->error_category);
    }
}
