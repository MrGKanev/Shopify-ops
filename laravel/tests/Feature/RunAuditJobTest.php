<?php

namespace Tests\Feature;

use App\Application\Reports\AuditResult;
use App\Application\Reports\RunAudit;
use App\Jobs\RunAuditJob;
use App\Models\Store;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
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
}
