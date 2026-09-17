<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SavedReportControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_store_isolation_and_safe_rendering(): void
    {
        $this->get('/saved-reports')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/saved-reports')->assertForbidden();
        [$operator, $store] = $this->userWithStore(true);
        [, $otherStore] = $this->userWithStore(true);
        $report = $this->report($store, '<script>x</script>');
        $other = $this->report($otherStore, '#2002');

        $this->actingAs($operator)->get('/saved-reports')->assertOk()->assertSeeText('Saved Reports')->assertSeeText('run_audit');
        $this->actingAs($operator)->get(route('saved-reports.show', $report))->assertOk()->assertSeeText('<script>x</script>')->assertDontSee('<script>', false);
        $this->actingAs($operator)->get(route('saved-reports.show', $other))->assertNotFound();
    }

    public function test_csv_is_formula_safe(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $report = $this->report($store, '=IMPORTXML("bad")');

        $response = $this->actingAs($operator)->get(route('saved-reports.export', $report));

        $response->assertOk()->assertDownload('run-audit-2026-09-09.csv');
        $this->assertStringContainsString("'=IMPORTXML", $response->streamedContent());
    }

    public function test_show_includes_history_recurrence_and_quick_actions(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-07', 'start_date' => '2026-09-01', 'end_date' => '2026-09-07', 'rows_found' => 1, 'result' => ['missing' => [['id' => '42', 'name' => '#1001', 'created_at' => '2026-09-01', 'email' => 'a@example.com', 'total_price' => 10]]]]);
        $report = $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-09', 'start_date' => '2026-09-01', 'end_date' => '2026-09-09', 'rows_found' => 1, 'result' => ['missing' => [['id' => '42', 'name' => '#1001', 'created_at' => '2026-09-01', 'email' => 'a@example.com', 'total_price' => 10]]]]);

        $response = $this->actingAs($operator)->get(route('saved-reports.show', $report))->assertOk();

        $response->assertViewHas('recurrenceCounts', fn ($counts) => $counts['1001'] === 2);
        $response->assertViewHas('history', fn ($history) => $history->count() === 2);
        $response->assertSee(route('orders.spot-check', ['order_number' => '1001']), false);
        $response->assertSee(route('orders.timeline', ['order_number' => '1001']), false);
        $response->assertSee(route('ignored-orders.store'), false);
        $response->assertSee(route('reports.run-audit.store'), false);
    }

    private function report(Store $store, string $name): mixed
    {
        return $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-09', 'start_date' => '2026-09-01', 'end_date' => '2026-09-09', 'rows_found' => 1, 'result' => ['missing' => [['name' => $name, 'created_at' => '2026-09-01', 'email' => 'a@example.com', 'total_price' => 10]]]]);
    }

    /** @return array{User,Store} */
}
