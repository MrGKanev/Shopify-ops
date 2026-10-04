<?php

namespace Tests\Unit\Application\Operations;

use App\Application\Operations\SyncAuditIssues;
use App\IssuePriority;
use App\IssueStatus;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncAuditIssuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_tracks_recurrence_and_resolves_missing_orders_that_clear(): void
    {
        $store = Store::factory()->create();
        $sync = app(SyncAuditIssues::class);

        $sync->handle($store, [['name' => '#1001', 'total_price' => 600]]);
        $sync->handle($store, [['name' => '#1001', 'total_price' => 600]]);

        $issue = $store->operationalIssues()->sole();
        $this->assertSame(IssuePriority::High, $issue->priority);
        $this->assertSame(2, $issue->occurrences);

        $sync->handle($store, []);

        $this->assertSame(IssueStatus::Resolved, $issue->fresh()->status);
        $this->assertNotNull($issue->fresh()->resolved_at);
    }

    public function test_it_reopens_an_ignored_issue_when_the_order_is_missing_again(): void
    {
        $store = Store::factory()->create();
        $sync = app(SyncAuditIssues::class);

        $sync->handle($store, [['name' => '#1001']]);
        $issue = $store->operationalIssues()->sole();
        $issue->update(['status' => 'ignored']);

        $sync->handle($store, [['name' => '#1001']]);

        $this->assertSame(IssueStatus::Open, $issue->fresh()->status);
        $this->assertNull($issue->fresh()->resolved_at);
        $this->assertSame(2, $issue->fresh()->occurrences);
    }
}
