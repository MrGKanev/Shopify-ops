<?php

namespace Tests\Feature;

use App\Models\OperationalIssue;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CommandPaletteControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_requires_an_operator_and_validates_the_query(): void
    {
        $this->getJson(route('command-palette'))->assertUnauthorized();
        [$viewer] = $this->makeUserAndStore();
        $this->actingAs($viewer)->getJson(route('command-palette'))->assertForbidden();
        [$operator] = $this->makeUserAndStore(true);

        $this->actingAs($operator)->getJson(route('command-palette', ['q' => str_repeat('x', 65)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_it_searches_navigation_issues_and_runs_with_store_isolation(): void
    {
        [$operator, $store] = $this->makeUserAndStore(true);
        [, $otherStore] = $this->makeUserAndStore(true);
        $issue = OperationalIssue::factory()->for($store)->create(['title' => 'Refund needs attention', 'reference' => '#4401']);
        OperationalIssue::factory()->for($otherStore)->create(['title' => 'Refund from another store']);
        $store->runLogs()->create(['tool' => 'refund_tracker', 'status' => 'ok', 'error' => '']);
        $otherStore->runLogs()->create(['tool' => 'refund_other_store', 'status' => 'ok', 'error' => '']);

        $response = $this->actingAs($operator)->getJson(route('command-palette', ['q' => 'refund']))
            ->assertOk()
            ->assertJsonFragment(['label' => 'Refund needs attention', 'kind' => 'Issue'])
            ->assertJsonFragment(['label' => 'Refund Tracker', 'kind' => 'Run'])
            ->assertJsonMissing(['label' => 'Refund from another store'])
            ->assertJsonMissing(['label' => 'Refund Other Store']);

        $issueResult = collect($response->json('results'))->firstWhere('label', 'Refund needs attention');

        $this->assertSame(route('operational-issues.index').'#issue-'.$issue->getKey(), $issueResult['url']);
    }

    public function test_it_searches_local_orders_and_reports_with_store_isolation(): void
    {
        [$operator, $store] = $this->makeUserAndStore(true);
        [, $otherStore] = $this->makeUserAndStore(true);
        $report = $store->auditSnapshots()->create(['tool' => 'refund_audit', 'report_date' => '2026-09-09', 'start_date' => '2026-09-01', 'end_date' => '2026-09-09', 'rows_found' => 1, 'result' => []]);
        $store->pushLogs()->create(['order_number' => '4401', 'shopify_id' => '1', 'pushed_at' => now()]);
        $store->ignoredOrders()->create(['order_number' => '4402', 'reason' => 'Refund requested', 'ignored_at' => today()]);
        $store->printQueueItems()->create(['order_number' => '4403', 'note' => 'Refund paperwork']);
        $otherStore->pushLogs()->create(['order_number' => '4404', 'shopify_id' => '2', 'pushed_at' => now()]);

        $this->actingAs($operator)->getJson(route('command-palette', ['q' => 'refund']))
            ->assertJsonFragment(['label' => 'Refund Audit', 'kind' => 'Report'])
            ->assertJsonFragment(['label' => '#4402', 'kind' => 'Order'])
            ->assertJsonFragment(['label' => '#4403', 'kind' => 'Order'])
            ->assertJsonFragment(['url' => route('saved-reports.show', $report)])
            ->assertJsonMissing(['label' => '#4404']);

        $this->actingAs($operator)->getJson(route('command-palette', ['q' => '4401']))
            ->assertJsonFragment(['label' => '#4401', 'kind' => 'Order'])
            ->assertJsonFragment(['url' => route('orders.lookup', ['order_number' => '4401'])])
            ->assertJsonFragment(['url' => route('global-search', ['q' => '4401'])]);
    }

    public function test_admin_navigation_is_not_exposed_to_operators(): void
    {
        [$operator] = $this->makeUserAndStore(true);
        [$administrator] = $this->makeUserAndStore(true, true);

        $this->actingAs($operator)->getJson(route('command-palette', ['q' => 'backup']))
            ->assertOk()
            ->assertJsonCount(0, 'results');
        $this->actingAs($administrator)->getJson(route('command-palette', ['q' => 'backup']))
            ->assertOk()
            ->assertJsonFragment(['label' => 'Backups', 'kind' => 'Page']);
        $this->actingAs($administrator)->getJson(route('command-palette', ['q' => 'disco']))
            ->assertOk()
            ->assertJsonFragment(['label' => 'Discord notifications', 'url' => route('admin.discord-rules.edit'), 'kind' => 'Page']);
    }

    public function test_it_finds_audit_tools_by_what_they_check(): void
    {
        [$operator] = $this->makeUserAndStore(true);

        $this->actingAs($operator)->getJson(route('command-palette', ['q' => 'still unfulfilled']))
            ->assertOk()
            ->assertJsonFragment(['label' => 'SS Shipped / Shopify Unfulfilled', 'kind' => 'Page']);

        $this->actingAs($operator)->getJson(route('command-palette', ['q' => 'wrong item mismatch']))
            ->assertOk()
            ->assertJsonFragment(['label' => 'Shipped Item Mismatch', 'kind' => 'Page']);
    }

    public function test_it_translates_search_results_into_bulgarian(): void
    {
        [$operator] = $this->makeUserAndStore(true);
        app()->setLocale('bg');

        $this->actingAs($operator)->getJson(route('command-palette', ['q' => 'still unfulfilled']))
            ->assertJsonFragment([
                'label' => 'Изпратени в SS / неизпълнени в Shopify',
                'description' => 'Открива поръчки, изпратени от ShipStation, които Shopify все още отбелязва като неизпълнени.',
                'kind' => 'Page',
            ]);
    }

    public function test_layout_includes_the_keyboard_accessible_palette_for_operators(): void
    {
        [$operator] = $this->makeUserAndStore(true);

        $this->actingAs($operator)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-command-palette-open', false)
            ->assertSee('data-command-palette-input', false)
            ->assertSee('Ctrl/⌘ K Search');
    }

    /** @return array{User, Store} */
    private function makeUserAndStore(bool $operator = false, bool $administrator = false): array
    {
        $factory = User::factory();
        if ($administrator) {
            $factory = $factory->admin();
        } elseif ($operator) {
            $factory = $factory->operator();
        }
        $user = $factory->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
