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

    public function test_audit_page_sidebar_lists_a_tool_absent_from_the_old_flat_list(): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->get(route('reports.run-audit'))->assertOk()
            ->assertSeeText('Order Issues')
            ->assertSeeText('Duplicate Detector')
            ->assertSeeText('Fraud & Compliance')
            ->assertSeeText('Chargebacks / Disputes');
    }

    public function test_search_page_sidebar_lists_customer_ltv_and_tag_audit(): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->get(route('orders.lookup'))->assertOk()
            ->assertSeeText('Customer LTV')
            ->assertSeeText('Tag Audit');
    }

    /** @return array{User, Store} */
    private function userWithStore(bool $operator = false): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
