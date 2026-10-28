<?php

namespace Tests\Feature;

use App\Application\Orders\ExecuteRemediation;
use App\Application\Orders\PrepareRemediationPreview;
use App\Jobs\ExecuteOrderRemediation;
use App\Jobs\PrepareOrderRemediation;
use App\Models\RemediationRun;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RemediationJobLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_prepare_ignores_a_run_that_is_no_longer_preparing(): void
    {
        $run = RemediationRun::factory()->create(['status' => 'ready']);
        $prepare = Mockery::mock(PrepareRemediationPreview::class);
        $prepare->shouldNotReceive('handle');

        (new PrepareOrderRemediation($run->id))->handle($prepare);

        $this->assertSame('ready', $run->fresh()->status);
    }

    public function test_prepare_in_a_cancelled_batch_cancels_the_run_without_calling_integrations(): void
    {
        $run = RemediationRun::factory()->create(['status' => 'preparing']);
        $prepare = Mockery::mock(PrepareRemediationPreview::class);
        $prepare->shouldNotReceive('handle');

        $this->inCancelledBatch(PrepareOrderRemediation::class, $run)->handle($prepare);

        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_failed_preparation_blocks_only_runs_still_preparing(): void
    {
        $preparing = RemediationRun::factory()->create(['status' => 'preparing']);
        $ready = RemediationRun::factory()->create(['status' => 'ready']);

        (new PrepareOrderRemediation($preparing->id))->failed(new RuntimeException('secret-token'));
        (new PrepareOrderRemediation($ready->id))->failed(new RuntimeException('secret-token'));

        $this->assertSame('blocked', $preparing->fresh()->status);
        $this->assertNull($preparing->fresh()->plan);
        $this->assertStringNotContainsString('secret-token', (string) $preparing->fresh()->result_message);
        $this->assertSame('ready', $ready->fresh()->status);
    }

    public function test_execute_in_a_cancelled_batch_cancels_the_run_without_writes(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $run = RemediationRun::factory()->for($store)->for($operator, 'user')->create(['status' => 'queued']);
        $execute = Mockery::mock(ExecuteRemediation::class);
        $execute->shouldNotReceive('handle');

        $this->inCancelledBatch(ExecuteOrderRemediation::class, $run)->handle($execute);

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_integration_failure_is_recorded_without_leaking_the_remote_error(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $run = RemediationRun::factory()->for($store)->for($operator, 'user')->create(['status' => 'queued']);
        $execute = Mockery::mock(ExecuteRemediation::class);
        $execute->shouldReceive('handle')->once()->andThrow(new RuntimeException('secret-token'));

        (new ExecuteOrderRemediation($run->id))->handle($execute);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(RuntimeException::class, $run->error_category);
        $this->assertStringNotContainsString('secret-token', (string) $run->result_message);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'operator-actions', 'description' => 'remediation_failed', 'subject_id' => $run->id]);
    }

    public function test_stopped_execution_fails_open_runs_but_never_overwrites_a_completed_one(): void
    {
        $running = RemediationRun::factory()->create(['status' => 'running']);
        $completed = RemediationRun::factory()->create(['status' => 'completed']);

        (new ExecuteOrderRemediation($running->id))->failed(null);
        (new ExecuteOrderRemediation($completed->id))->failed(new RuntimeException('late'));

        $this->assertSame('failed', $running->fresh()->status);
        $this->assertSame('unknown', $running->fresh()->error_category);
        $this->assertSame('completed', $completed->fresh()->status);
    }

    /**
     * @param  class-string<PrepareOrderRemediation|ExecuteOrderRemediation>  $job
     */
    private function inCancelledBatch(string $job, RemediationRun $run): PrepareOrderRemediation|ExecuteOrderRemediation
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('cancelled')->andReturn(true);
        $instance = Mockery::mock($job, [$run->id])->makePartial();
        $instance->shouldReceive('batch')->andReturn($batch);

        return $instance;
    }
}
