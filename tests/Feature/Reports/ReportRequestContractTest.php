<?php

namespace Tests\Feature\Reports;

use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Jobs\RunQueuedReport;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReportRequestContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_are_rejected_before_queuing_or_calling_the_gateway(string $slug, string $method, string $field, array $changes): void
    {
        [$operator] = $this->userWithStore(true);
        Queue::fake([RunQueuedReport::class]);
        $this->mock(ShopifyOrders::class)->shouldNotReceive($method);

        $this->actingAs($operator)->post(route('reports.'.$slug.'.store'), [...$this->input(), ...$changes])->assertSessionHasErrors($field);

        $this->assertDatabaseCount('report_runs', 0);
        $this->assertDatabaseCount('run_logs', 0);
        Queue::assertNothingPushed();
    }

    #[DataProvider('reports')]
    public function test_incomplete_credentials_prevent_report_runs_and_gateway_calls(string $slug, string $method): void
    {
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        Queue::fake([RunQueuedReport::class]);
        $this->mock(ShopifyOrders::class)->shouldNotReceive($method);

        $this->actingAs($operator)->post(route('reports.'.$slug.'.store'), $this->input())->assertSeeText('credentials are incomplete');

        $this->assertDatabaseCount('report_runs', 0);
        $this->assertDatabaseCount('run_logs', 0);
        Queue::assertNothingPushed();
    }

    #[DataProvider('reports')]
    public function test_gateway_failure_records_a_failed_run_for_the_active_store_without_leaking_details(string $slug, string $method): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $this->mock(ShopifyOrders::class)->shouldReceive($method)->once()
            ->withArgs(fn (Store $selected, mixed ...$arguments): bool => $selected->is($store))
            ->andThrow(new RuntimeException('private-access-token'));

        $this->actingAs($operator)->post(route('reports.'.$slug.'.store'), $this->input())
            ->assertSeeText('could not be completed')->assertDontSeeText('private-access-token');

        $run = $store->reportRuns()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertNull($run->result());
        $this->assertDatabaseHas('run_logs', ['store_id' => $store->id, 'status' => 'error', 'rows_found' => 0]);
    }

    public static function reports(): array
    {
        return [
            'on hold' => ['on-hold-stall', 'onHoldFulfillmentCandidates'],
            'country' => ['country-mismatch', 'countryMismatchCandidates'],
            'address' => ['duplicate-addresses', 'addressCheckCandidates'],
            'IP' => ['same-ip', 'sameIpCandidates'],
            'note' => ['note-flags', 'noteFlagCandidates'],
            'duplicates' => ['duplicate-orders', 'duplicateOrderCandidates'],
        ];
    }

    public static function invalidDates(): array
    {
        $cases = [];
        foreach (self::reports() as $name => [$slug, $method]) {
            foreach ([
                'start format' => ['start_date', ['start_date' => 'bad']],
                'end format' => ['end_date', ['end_date' => 'bad']],
                'reversed range' => ['end_date', ['end_date' => '2026-05-31']],
                'missing dates' => ['start_date', ['start_date' => null, 'end_date' => null]],
            ] as $condition => [$field, $changes]) {
                $cases[$name.' / '.$condition] = [$slug, $method, $field, $changes];
            }
        }

        return $cases;
    }

    /** @return array<string, string> */
    private function input(): array
    {
        return ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'keywords' => 'hold'];
    }
}
