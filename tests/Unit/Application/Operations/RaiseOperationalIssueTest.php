<?php

namespace Tests\Unit\Application\Operations;

use App\Application\Operations\RaiseOperationalIssue;
use App\IssuePriority;
use App\IssueStatus;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaiseOperationalIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_fingerprints_match_the_previously_stored_format(): void
    {
        $this->assertSame(hash('sha256', 'run_audit|1001'), RaiseOperationalIssue::fingerprint('run_audit', '1001'));
        $this->assertSame(hash('sha256', 'webhook|refunds/create|55'), RaiseOperationalIssue::fingerprint('webhook', 'refunds/create|55'));
    }

    public function test_it_creates_an_issue_and_counts_repeated_sightings(): void
    {
        $store = Store::factory()->create();
        $issues = app(RaiseOperationalIssue::class);

        $issues->handle($store, $this->attributes());
        $this->travel(1)->hour();
        $issue = $issues->handle($store, $this->attributes(['priority' => 'urgent']));

        $this->assertSame(IssueStatus::Open, $issue->status);
        $this->assertSame(IssuePriority::Urgent, $issue->priority);
        $this->assertSame(2, $issue->occurrences);
        $this->assertTrue($issue->first_seen_at->lt($issue->last_seen_at));
        $this->assertSame(1, $store->operationalIssues()->count());
    }

    public function test_it_keeps_in_progress_status_and_reopens_resolved_issues(): void
    {
        $store = Store::factory()->create();
        $issues = app(RaiseOperationalIssue::class);
        $issue = $issues->handle($store, $this->attributes());

        $issue->update(['status' => 'in_progress']);
        $this->assertSame(IssueStatus::InProgress, $issues->handle($store, $this->attributes())->status);

        $issue->update(['status' => 'resolved', 'resolved_at' => now()]);
        $reopened = $issues->handle($store, $this->attributes());
        $this->assertSame(IssueStatus::Open, $reopened->status);
        $this->assertNull($reopened->resolved_at);
    }

    public function test_ignored_issues_stay_ignored_when_reopening_ignored_is_disabled(): void
    {
        $store = Store::factory()->create();
        $issues = app(RaiseOperationalIssue::class);
        $issues->handle($store, $this->attributes())->update(['status' => 'ignored']);

        $this->assertSame(IssueStatus::Ignored, $issues->handle($store, $this->attributes(), reopenIgnored: false)->status);
        $this->assertSame(IssueStatus::Open, $issues->handle($store, $this->attributes())->status);
    }

    public function test_daily_counting_ignores_sightings_within_the_same_day(): void
    {
        $store = Store::factory()->create();
        $issues = app(RaiseOperationalIssue::class);

        $issues->handle($store, $this->attributes(), countOncePerDay: true);
        $this->travel(2)->hours();
        $this->assertSame(1, $issues->handle($store, $this->attributes(), countOncePerDay: true)->occurrences);
        $this->travel(25)->hours();
        $this->assertSame(2, $issues->handle($store, $this->attributes(), countOncePerDay: true)->occurrences);
    }

    public function test_resolve_stale_only_touches_active_issues_from_the_same_source(): void
    {
        $store = Store::factory()->create();
        $issues = app(RaiseOperationalIssue::class);
        $seen = $issues->handle($store, $this->attributes());
        $stale = $issues->handle($store, $this->attributes(['fingerprint' => 'stale', 'reference' => '#1002']));
        $ignored = $issues->handle($store, $this->attributes(['fingerprint' => 'ignored', 'reference' => '#1003']));
        $ignored->update(['status' => 'ignored']);
        $otherSource = $issues->handle($store, $this->attributes(['source_tool' => 'delivery_watch', 'fingerprint' => 'other']));

        $resolved = $issues->resolveStale($store, 'run_audit', [$seen->fingerprint]);

        $this->assertSame(1, $resolved);
        $this->assertSame(IssueStatus::Open, $seen->fresh()->status);
        $this->assertSame(IssueStatus::Resolved, $stale->fresh()->status);
        $this->assertNotNull($stale->fresh()->resolved_at);
        $this->assertSame(IssueStatus::Ignored, $ignored->fresh()->status);
        $this->assertSame(IssueStatus::Open, $otherSource->fresh()->status);
    }

    public function test_resolve_leaves_ignored_issues_untouched(): void
    {
        $store = Store::factory()->create();
        $issues = app(RaiseOperationalIssue::class);
        $issue = $issues->handle($store, $this->attributes());
        $issue->update(['status' => 'ignored']);

        $issues->resolve($issue);

        $this->assertSame(IssueStatus::Ignored, $issue->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{source_tool: string, fingerprint: string, title: string, priority: string, reference: string, payload: array<string, mixed>}
     */
    private function attributes(array $overrides = []): array
    {
        return [
            'source_tool' => 'run_audit',
            'fingerprint' => RaiseOperationalIssue::fingerprint('run_audit', '1001'),
            'title' => 'Missing order #1001',
            'priority' => 'normal',
            'reference' => '#1001',
            'payload' => ['name' => '#1001'],
            ...$overrides,
        ];
    }
}
