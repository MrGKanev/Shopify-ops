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
            ->assertJsonPath('results.0.label', 'Refund needs attention')
            ->assertJsonFragment(['label' => 'Refund Tracker', 'kind' => 'Run'])
            ->assertJsonMissing(['label' => 'Refund from another store'])
            ->assertJsonMissing(['label' => 'Refund Other Store']);

        $this->assertStringContainsString('#issue-'.$issue->getKey(), $response->json('results.0.url'));
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
