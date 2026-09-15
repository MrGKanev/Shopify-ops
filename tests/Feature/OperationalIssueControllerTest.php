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
        ])->assertSessionHas('status', 'Issue updated.');

        $this->assertDatabaseHas('operational_issues', [
            'id' => $issue->getKey(),
            'status' => 'in_progress',
            'priority' => 'high',
            'owner_user_id' => $operator->getKey(),
        ]);

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

    /** @return array{User, Store} */
    private function operatorWithStore(): array
    {
        $user = User::factory()->operator()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
