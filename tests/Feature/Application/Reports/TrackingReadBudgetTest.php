<?php

namespace Tests\Feature\Application\Reports;

use App\Application\Reports\TrackingReadBudget;
use App\Integrations\Exceptions\TrackingReadLimitExceeded;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrackingReadBudgetTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(int $limit): Store
    {
        return Store::factory()->create(['movement_policy' => ['daily_limit' => $limit]]);
    }

    private function attempts(Store $store, string $date): ?int
    {
        $value = DB::table('tracking_read_budgets')->where(['store_id' => $store->id, 'budget_date' => $date])->value('attempts');

        return $value === null ? null : (int) $value;
    }

    public function test_exactly_the_daily_limit_can_be_claimed(): void
    {
        $this->travelTo('2026-10-24 10:00:00 UTC');
        $store = $this->store(3);
        $budget = app(TrackingReadBudget::class);

        for ($claim = 1; $claim <= 3; $claim++) {
            $budget->claim($store);
            $this->assertSame(3 - $claim, $budget->remaining($store));
        }

        $this->expectException(TrackingReadLimitExceeded::class);
        $budget->claim($store);
    }

    public function test_a_rejected_claim_leaves_the_counter_at_the_limit(): void
    {
        $this->travelTo('2026-10-24 10:00:00 UTC');
        $store = $this->store(1);
        $budget = app(TrackingReadBudget::class);
        $budget->claim($store);

        foreach (range(1, 3) as $ignored) {
            try {
                $budget->claim($store);
                $this->fail('The budget is exhausted.');
            } catch (TrackingReadLimitExceeded) {
            }
        }

        $this->assertSame(1, $this->attempts($store, '2026-10-24'));
        $this->assertSame(0, $budget->remaining($store));
    }

    public function test_a_stale_check_by_a_concurrent_claimer_cannot_exceed_the_limit(): void
    {
        $this->travelTo('2026-10-24 10:00:00 UTC');
        $store = $this->store(5);
        $budget = app(TrackingReadBudget::class);
        $budget->claim($store);
        // Two workers have both seen four free reads; the first has already taken the fifth.
        DB::table('tracking_read_budgets')->where('store_id', $store->id)->update(['attempts' => 4]);
        $this->assertSame(1, $budget->remaining($store));

        $budget->claim($store);
        DB::table('tracking_read_budgets')->where('store_id', $store->id)->update(['attempts' => 5]);

        $this->expectException(TrackingReadLimitExceeded::class);
        try {
            $budget->claim($store);
        } finally {
            $this->assertSame(5, $this->attempts($store, '2026-10-24'));
        }
    }

    public function test_the_budget_resets_at_the_utc_day_boundary_and_keeps_the_previous_day(): void
    {
        $this->travelTo('2026-10-24 23:59:59 UTC');
        $store = $this->store(1);
        $budget = app(TrackingReadBudget::class);
        $budget->claim($store);
        $this->assertSame(0, $budget->remaining($store));

        $this->travelTo('2026-10-25 00:00:00 UTC');
        $this->assertSame(1, $budget->remaining($store));
        $budget->claim($store);

        $this->assertSame(1, $this->attempts($store, '2026-10-24'));
        $this->assertSame(1, $this->attempts($store, '2026-10-25'));
        $this->assertSame(0, $budget->remaining($store));
    }

    public function test_budgets_are_counted_per_store_and_a_lowered_limit_applies_to_existing_usage(): void
    {
        $this->travelTo('2026-10-24 10:00:00 UTC');
        $first = $this->store(3);
        $second = $this->store(3);
        $budget = app(TrackingReadBudget::class);
        $budget->claim($first);
        $budget->claim($first);

        $this->assertSame(1, $budget->remaining($first));
        $this->assertSame(3, $budget->remaining($second));

        $first->update(['movement_policy' => ['daily_limit' => 1]]);
        $this->assertSame(0, $budget->remaining($first->fresh()));
        $this->expectException(TrackingReadLimitExceeded::class);
        $budget->claim($first->fresh());
    }
}
