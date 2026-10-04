<?php

namespace Tests\Feature\Reports;

use App\Application\Reports\RecordRun;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Jobs\RunQueuedReport;
use App\Models\ReportRun;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class QueuedReportRunTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const array INPUT = ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'];

    public function test_a_submitted_report_runs_on_the_queue_and_its_result_url_waits_then_shows_the_result(): void
    {
        Queue::fake();
        [$operator, $store] = $this->userWithStore(true);
        $resultUrl = route('reports.on-hold-stall.result', self::INPUT);

        $this->actingAs($operator)->post(route('reports.on-hold-stall.store'), self::INPUT)->assertRedirect($resultUrl);

        $run = ReportRun::query()->sole();
        $this->assertSame('queued', $run->status);
        $this->assertSame([self::INPUT['start_date'], self::INPUT['end_date']], $run->arguments);
        Queue::assertPushed(RunQueuedReport::class, fn (RunQueuedReport $job): bool => $job->reportRunId === $run->getKey());

        $this->actingAs($operator)->get($resultUrl)->assertOk()
            ->assertSee('<meta http-equiv="refresh" content="3">', false)
            ->assertSeeText('The report is running in the background');
        $this->actingAs($operator)->post(route('reports.on-hold-stall.store'), self::INPUT)->assertRedirect($resultUrl);
        $this->assertSame(1, ReportRun::query()->count(), 'An identical pending run is reused.');

        $this->fakeShopify(times: 1);
        (new RunQueuedReport($run->getKey()))->handle(app(RecordRun::class));

        $this->assertSame('completed', $run->fresh()->status);
        $this->actingAs($operator)->get($resultUrl)->assertOk()
            ->assertSeeText('1 on-hold fulfillment orders')
            ->assertDontSee('http-equiv="refresh"', false);
        $this->assertDatabaseHas('run_logs', ['store_id' => $store->getKey(), 'tool' => 'on_hold_stall', 'status' => 'ok', 'scanned' => 1, 'rows_found' => 1]);
        $this->assertSame(1, $store->runLogs()->count(), 'Viewing the result again does not record another run.');

        $export = $this->actingAs($operator)->post(route('reports.on-hold-stall.export'), self::INPUT);
        $export->assertOk()->assertDownload('on-hold-stalls-2026-06-01-to-2026-06-30.csv');
    }

    public function test_a_failed_run_shows_the_failure_and_records_an_error(): void
    {
        Queue::fake();
        [$operator, $store] = $this->userWithStore(true);
        $this->actingAs($operator)->post(route('reports.on-hold-stall.store'), self::INPUT);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('onHoldFulfillmentCandidates')->once()->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        (new RunQueuedReport(ReportRun::query()->sole()->getKey()))->handle(app(RecordRun::class));

        $this->actingAs($operator)->get(route('reports.on-hold-stall.result', self::INPUT))->assertOk()
            ->assertSeeText('could not be completed')
            ->assertDontSeeText('secret-token');
        $this->assertDatabaseHas('run_logs', ['store_id' => $store->getKey(), 'tool' => 'on_hold_stall', 'status' => 'error']);
    }

    public function test_runs_and_stored_results_are_scoped_to_their_store(): void
    {
        [$owner, $ownerStore] = $this->userWithStore(true);
        $this->fakeShopify(times: 1);
        $this->actingAs($owner)->post(route('reports.on-hold-stall.store'), self::INPUT)->assertOk()->assertSeeText('1 on-hold fulfillment orders');
        $this->assertNotSame('', (string) ReportRun::query()->sole()->getRawOriginal('result'));
        $this->assertStringNotContainsString('legacyResourceId', (string) ReportRun::query()->sole()->getRawOriginal('result'), 'Results are stored encrypted.');

        [$otherOperator] = $this->userWithStore(true);
        $this->fakeShopify(times: 1);
        $this->actingAs($otherOperator)->get(route('reports.on-hold-stall.result', self::INPUT))->assertOk();

        $this->assertSame(2, ReportRun::query()->count(), 'Another store never reuses this store\'s run.');
        $this->assertSame(1, $ownerStore->reportRuns()->count());
    }

    private function fakeShopify(int $times): void
    {
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('onHoldFulfillmentCandidates')->times($times)->andReturn([
            'fulfillment_orders' => [['order' => ['legacyResourceId' => '1', 'name' => '#1001', 'createdAt' => now()->subDays(10)->toIso8601String(), 'displayFinancialStatus' => 'PAID'], 'fulfillmentHolds' => [['reason' => 'MANUAL']]]],
            'pages' => 1,
            'truncated' => false,
        ]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
    }
}
