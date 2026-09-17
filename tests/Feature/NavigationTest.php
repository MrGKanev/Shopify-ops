<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_every_audit_and_search_hub_route_resolves(): void
    {
        foreach (array_merge(config('audit-hub'), config('search-hub')) as $section => $links) {
            foreach ($links as $link) {
                $this->assertTrue(Route::has($link['route']), "Missing route: {$link['route']} (section {$section})");
            }
        }
    }

    public function test_audits_index_lists_every_tool_grouped_by_category(): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->get(route('audits.index'))->assertOk()
            ->assertSeeText('Order Issues')
            ->assertSeeText('Duplicate Detector')
            ->assertSeeText('Fraud & Compliance')
            ->assertSeeText('Chargebacks / Disputes');
    }

    public function test_audit_page_sidebar_shows_recent_runs_instead_of_the_full_tool_list(): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->get(route('reports.run-audit'))->assertOk()
            ->assertSeeText('Recent Runs')
            ->assertSeeText('Full run history')
            ->assertDontSeeText('Order Issues')
            ->assertDontSeeText('Fraud & Compliance');
    }

    public function test_search_page_sidebar_lists_customer_ltv_and_tag_audit(): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->get(route('orders.lookup'))->assertOk()
            ->assertSeeText('Customer LTV')
            ->assertSeeText('Tag Audit');
    }

    /** @return array{User, Store} */
}
