<?php

namespace Tests\Feature;

use App\Models\AuditSnapshot;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReportTrendControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_validation_store_isolation_and_ordered_deltas(): void
    {
        $this->get('/report-trends')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/report-trends')->assertForbidden();
        [$operator, $store] = $this->userWithStore(true);
        [, $otherStore] = $this->userWithStore(true);
        $this->snapshot($store, '2026-09-01', 2);
        $this->snapshot($store, '2026-09-03', 5);
        $this->snapshot($store, '2026-09-05', 1);
        $other = $this->snapshot($otherStore, '2026-09-04', 99);

        $this->actingAs($operator)->get('/report-trends?start_date=bad&end_date=2026-09-09')->assertSessionHasErrors('start_date');
        $this->actingAs($operator)->get('/report-trends?start_date=2026-09-01&end_date=2026-09-05')->assertOk()->assertSeeTextInOrder(['2026-09-01', '2026-09-03', '+3', '2026-09-05', '-4'])->assertDontSee(route('saved-reports.show', $other), false);
    }

    public function test_aggregates_and_repeat_offenders(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $this->snapshot($store, '2026-09-01', 2, [['name' => '#1001'], ['name' => '#1002']]);
        $this->snapshot($store, '2026-09-03', 0, []);
        $this->snapshot($store, '2026-09-05', 1, [['name' => '#1001']]);

        $response = $this->actingAs($operator)->get('/report-trends?start_date=2026-09-01&end_date=2026-09-05')->assertOk();

        $response->assertViewHas('avgMissing', 1.0);
        $response->assertViewHas('clearReportCount', 1);
        $response->assertViewHas('uniqueMissingCount', 2);
        $response->assertViewHas('worstReport', fn ($worst) => $worst->rows_found === 2);
        $response->assertViewHas('repeatOffenders', fn ($offenders) => $offenders[0]['number'] === '#1001' && $offenders[0]['count'] === 2);
        $response->assertSeeText('#1001');
        $response->assertSee('form="bulk-ignore"', false);
        $response->assertSee('name="order_numbers[]" value="#1001"', false);
    }

    public function test_backlog_is_store_scoped_and_compares_exactly_seven_days_even_outside_the_selected_period(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        [, $otherStore] = $this->userWithStore(true);
        $this->backlog($store, '2026-10-16', 10);
        $this->backlog($store, '2026-10-22', 7);
        $today = $this->backlog($store, '2026-10-23', 4);
        $this->backlog($store, '2026-10-24', 0);
        $this->backlog($store, '2026-10-25', 100);
        $this->backlog($otherStore, '2026-10-23', 999);

        $response = $this->actingAs($operator)->get('/report-trends?start_date=2026-10-22&end_date=2026-10-24');

        $response->assertViewHas('backlogRows', function (array $rows): bool {
            return array_column($rows, 'date') === ['2026-10-22', '2026-10-23', '2026-10-24']
                && array_column($rows, 'total') === [7, 4, 0]
                && array_column($rows, 'weekly_delta') === [null, -6, null];
        });
        $response->assertViewHas('rows', []);
        $response->assertSeeText('Shipment backlog history')->assertSeeText('Missing days are unknown, not zero.');
        $response->assertSee(route('saved-reports.export', $today), false);
        $response->assertDontSeeText('999');
    }

    public function test_backlog_saved_report_redirect_and_export_preserve_store_isolation(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        [, $otherStore] = $this->userWithStore(true);
        $snapshot = $this->backlog($store, '2026-10-23', 4);
        $foreign = $this->backlog($otherStore, '2026-10-23', 99);

        $this->actingAs($operator)->get(route('saved-reports.show', $snapshot))->assertRedirect(route('report-trends.index', ['start_date' => '2026-10-23', 'end_date' => '2026-10-23']));
        $response = $this->get(route('saved-reports.export', $snapshot));

        $response->assertDownload('shipment-backlog-2026-10-23.csv');
        $this->assertStringContainsString('2026-10-23,2026-10-23T07:15:00+00:00,2026-10-23T07:15:00+00:00,UTC,4,0,4,4,0,0,0,0', $response->streamedContent());
        $this->get(route('saved-reports.export', $foreign))->assertNotFound();
        $this->get(route('saved-reports.show', $foreign))->assertNotFound();
    }

    private function backlog(Store $store, string $date, int $total): AuditSnapshot
    {
        return $store->auditSnapshots()->create([
            'tool' => 'shipment_backlog', 'report_date' => $date, 'start_date' => $date, 'end_date' => $date, 'rows_found' => $total,
            'result' => ['captured_at' => $date.'T07:15:00+00:00', 'completed_at' => $date.'T07:15:00+00:00', 'timezone' => 'UTC', 'source' => 'shipstation', 'awaiting' => $total, 'on_hold' => 0, 'age_buckets' => ['under_1_day' => $total, '1_2_days' => 0, '3_6_days' => 0, '7_plus_days' => 0, 'unknown' => 0]],
        ]);
    }

    private function snapshot(Store $store, string $date, int $missing, array $missingOrders = []): mixed
    {
        return $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => $date, 'start_date' => $date, 'end_date' => $date, 'rows_found' => $missing, 'result' => ['missing' => $missingOrders]]);
    }

    /** @return array{User,Store} */
}
