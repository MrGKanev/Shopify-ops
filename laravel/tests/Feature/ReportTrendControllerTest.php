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

    private function snapshot(Store $store, string $date, int $missing): mixed
    {
        return $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => $date, 'start_date' => $date, 'end_date' => $date, 'rows_found' => $missing, 'result' => ['missing' => []]]);
    }

    /** @return array{User,Store} */
    private function userWithStore(bool $operator = false): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
