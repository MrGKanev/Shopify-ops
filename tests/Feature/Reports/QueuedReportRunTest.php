<?php

namespace Tests\Feature\Reports;

use App\Application\Reports\RecordRun;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\RateLimited;
use App\Integrations\Exceptions\Unauthorized;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Jobs\RunQueuedReport;
use App\Models\ReportRun;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $gateway = Mockery::mock(ShopifyOrders::class);
        $gateway->shouldReceive('onHoldFulfillmentCandidates')->once()->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyOrders::class, $gateway);

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

    #[DataProvider('integrationFailures')]
    public function test_failed_reports_show_only_safe_integration_reasons(?IntegrationException $failure, string $visibleMessage): void
    {
        Queue::fake([RunQueuedReport::class]);
        [$operator, $store] = $this->userWithStore(true);
        $gateway = $this->mock(ShopifyOrders::class);
        $gateway->shouldReceive('onHoldFulfillmentCandidates')->once()->andThrow($failure ?? new RuntimeException('private-token'));
        $this->actingAs($operator)->post(route('reports.on-hold-stall.store'), self::INPUT)->assertRedirect();
        $run = ReportRun::query()->sole();

        (new RunQueuedReport($run->id))->handle(app(RecordRun::class));

        $this->actingAs($operator)->get(route('reports.on-hold-stall.result', self::INPUT))
            ->assertSeeText($visibleMessage)->assertDontSeeText('private-token');
        $this->assertNull($run->fresh()->result());
        $this->assertDatabaseHas('run_logs', ['store_id' => $store->id, 'tool' => 'on_hold_stall', 'status' => 'error', 'scanned' => 0, 'rows_found' => 0]);
    }

    public static function integrationFailures(): array
    {
        return [
            'rate limit with reset' => [new RateLimited(40), 'Try again after 40 seconds.'],
            'rate limit without reset' => [new RateLimited, 'Try again later.'],
            'credentials' => [new Unauthorized('The integration credentials were rejected.', 401), 'The integration credentials were rejected.'],
            'unexpected response' => [new UnexpectedResponse('The integration returned an unexpected HTTP response.', 503), 'The integration returned an unexpected response. Try again later.'],
            'unsafe upstream message' => [new UnexpectedResponse('private-token', 503), 'The integration returned an unexpected response. Try again later.'],
            'unknown failure' => [null, 'could not be completed'],
        ];
    }

    private function fakeShopify(int $times): void
    {
        $gateway = Mockery::mock(ShopifyOrders::class);
        $gateway->shouldReceive('onHoldFulfillmentCandidates')->times($times)->andReturn([
            'fulfillment_orders' => [['order' => ['legacyResourceId' => '1', 'name' => '#1001', 'createdAt' => now()->subDays(10)->toIso8601String(), 'displayFinancialStatus' => 'PAID'], 'fulfillmentHolds' => [['reason' => 'MANUAL']]]],
            'pages' => 1,
            'truncated' => false,
        ]);
        $this->app->instance(ShopifyOrders::class, $gateway);
    }
}
