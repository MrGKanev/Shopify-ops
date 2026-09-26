<?php

namespace Tests\Feature;

use App\Models\OperationalIssue;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalIssueControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_view_and_update_only_issues_in_the_active_store(): void
    {
        [$operator, $store] = $this->operatorWithStore();
        $issue = OperationalIssue::factory()->for($store)->create(['title' => 'Missing order #1001']);
        [, $otherStore] = $this->operatorWithStore();
        $otherIssue = OperationalIssue::factory()->for($otherStore)->create();

        $this->actingAs($operator)->get(route('operational-issues.index'))
            ->assertOk()
            ->assertSeeText('Missing order #1001');

        $this->actingAs($operator)->put(route('operational-issues.update', $issue), [
            'status' => 'in_progress',
            'priority' => 'high',
            'owner_user_id' => $operator->getKey(),
            'due_date' => '2026-10-01',
            'resolution_note' => 'Waiting for carrier confirmation.',
        ])->assertSessionHas('status', 'Issue updated.');

        $this->assertDatabaseHas('operational_issues', [
            'id' => $issue->getKey(),
            'status' => 'in_progress',
            'priority' => 'high',
            'owner_user_id' => $operator->getKey(),
            'resolution_note' => 'Waiting for carrier confirmation.',
        ]);
        $this->assertSame('2026-10-01', $issue->fresh()->due_date->toDateString());

        $this->actingAs($operator)->get(route('operational-issues.index'))
            ->assertSeeText('2026-10-01')
            ->assertSeeText('Waiting for carrier confirmation.');

        $this->actingAs($operator)->put(route('operational-issues.update', $otherIssue), [
            'status' => 'resolved',
            'priority' => 'normal',
        ])->assertNotFound();
    }

    public function test_viewer_cannot_access_issue_triage(): void
    {
        $viewer = User::factory()->create();
        $store = Store::factory()->create();
        $viewer->stores()->attach($store);

        $this->actingAs($viewer)->get(route('operational-issues.index'))->assertForbidden();
    }

    public function test_operator_can_filter_owned_and_overdue_issues(): void
    {
        $this->travelTo('2026-09-26 12:00:00');
        [$operator, $store] = $this->operatorWithStore();
        $ownedOverdue = OperationalIssue::factory()->for($store)->create(['title' => 'My overdue issue', 'owner_user_id' => $operator->id, 'due_date' => '2026-09-25']);
        OperationalIssue::factory()->for($store)->create(['title' => 'My future issue', 'owner_user_id' => $operator->id, 'due_date' => '2026-09-27']);
        OperationalIssue::factory()->for($store)->create(['title' => 'Other overdue issue', 'due_date' => '2026-09-25']);
        OperationalIssue::factory()->for($store)->create(['title' => 'Resolved overdue issue', 'status' => 'resolved', 'due_date' => '2026-09-25']);

        $this->actingAs($operator)->get(route('operational-issues.index', ['mine' => 1]))
            ->assertSeeText('My overdue issue')
            ->assertSeeText('My future issue')
            ->assertDontSeeText('Other overdue issue');

        $this->actingAs($operator)->get(route('operational-issues.index', ['overdue' => 1]))
            ->assertSeeText('My overdue issue')
            ->assertSeeText('Other overdue issue')
            ->assertSeeText('Overdue')
            ->assertDontSeeText('My future issue')
            ->assertDontSeeText('Resolved overdue issue');
    }

    /** @return array{User, Store} */
    private function operatorWithStore(): array
    {
        $user = User::factory()->operator()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
