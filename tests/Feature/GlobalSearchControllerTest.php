<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GlobalSearchControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_matches_all_sources_and_isolates_stores(): void
    {
        $this->get('/search')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/search')->assertForbidden();
        [$operator, $store] = $this->userWithStore(true);
        [, $otherStore] = $this->userWithStore(true);
        $report = $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-09', 'start_date' => '2026-09-01', 'end_date' => '2026-09-09', 'rows_found' => 1, 'result' => ['missing' => [['name' => '#1001<script>']]]]);
        $store->pushLogs()->create(['order_number' => '#1001', 'shopify_id' => '1', 'pushed_at' => now()]);
        $store->ignoredOrders()->create(['order_number' => '1001', 'reason' => '<img>', 'ignored_at' => today()]);
        $otherStore->pushLogs()->create(['order_number' => '1001-other', 'shopify_id' => '2', 'pushed_at' => now()]);

        $this->actingAs($operator)->get('/search?q=letters')->assertSessionHasErrors('q');
        $this->actingAs($operator)->get('/search?q=%231001')->assertOk()->assertSeeText('3 matches')->assertSeeText('Saved reports')->assertSeeText('Push log')->assertSeeText('Ignored orders')->assertSee(route('saved-reports.show', $report), false)->assertDontSee('<script>', false)->assertDontSee('<img>', false)->assertDontSeeText('1001-other');
    }

    private function userWithStore(bool $operator = false): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
