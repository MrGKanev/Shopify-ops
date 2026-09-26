<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReportTrendControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_store_isolation_and_ordered_deltas(): void
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

    private function snapshot(Store $store, string $date, int $missing, array $missingOrders = []): mixed
    {
        return $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => $date, 'start_date' => $date, 'end_date' => $date, 'rows_found' => $missing, 'result' => ['missing' => $missingOrders]]);
    }

    /** @return array{User,Store} */
}
