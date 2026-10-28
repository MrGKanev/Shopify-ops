<?php

namespace Tests\Feature\Application\Operations;

use App\Application\Operations\RaiseOperationalIssue;
use App\Application\Operations\SyncReturnExceptionIssues;
use App\IssueStatus;
use App\Models\OperationalIssue;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SyncReturnExceptionIssuesTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, mixed> */
    private function row(string $returnId, string $kind = 'not_received'): array
    {
        return ['return_id' => $returnId, 'kind' => $kind, 'return_name' => '#R-'.$returnId, 'order_number' => '1001', 'next_action' => 'Check receipt'];
    }

    private function sync(Store $store, array $rows, array $complete): void
    {
        app(SyncReturnExceptionIssues::class)->handle($store, $rows, $complete, '2026-10-01', '2026-10-28');
    }

    public function test_each_exception_becomes_one_issue_with_the_scanned_period_in_its_payload(): void
    {
        $store = Store::factory()->create();

        $this->sync($store, [$this->row('1'), $this->row('1', 'unprocessed'), $this->row('2')], ['1', '2']);

        $this->assertSame(3, $store->operationalIssues()->where('source_tool', 'return_exceptions')->count());
        $issue = $store->operationalIssues()->where('fingerprint', RaiseOperationalIssue::fingerprint('return_exceptions', '1|not_received'))->sole();
        $this->assertSame(['Check receipt · #R-1 · 1001', '1', IssueStatus::Open], [$issue->title, $issue->reference, $issue->status]);
        $this->assertSame(['2026-10-01', '2026-10-28'], [$issue->payload['start_date'], $issue->payload['end_date']]);
    }

    public function test_a_repeated_scan_on_the_same_day_does_not_inflate_the_occurrence_count(): void
    {
        $store = Store::factory()->create();

        $this->sync($store, [$this->row('1')], ['1']);
        $this->sync($store, [$this->row('1')], ['1']);

        $this->assertSame(1, $store->operationalIssues()->sole()->occurrences);
    }

    public function test_an_ignored_issue_stays_ignored_when_the_exception_is_seen_again(): void
    {
        $store = Store::factory()->create();
        $this->sync($store, [$this->row('1')], ['1']);
        $store->operationalIssues()->update(['status' => IssueStatus::Ignored]);

        $this->sync($store, [$this->row('1')], ['1']);

        $this->assertSame(IssueStatus::Ignored, $store->operationalIssues()->sole()->status);
    }

    public function test_issues_resolve_only_for_returns_that_were_fully_scanned(): void
    {
        $store = Store::factory()->create();
        $this->sync($store, [$this->row('1'), $this->row('2'), $this->row('3')], ['1', '2', '3']);

        $this->sync($store, [$this->row('1')], ['1', '2']);

        $status = fn (string $returnId): IssueStatus => $store->operationalIssues()->where('reference', $returnId)->sole()->status;
        $this->assertSame(IssueStatus::Open, $status('1'));
        $this->assertSame(IssueStatus::Resolved, $status('2'));
        $this->assertSame(IssueStatus::Open, $status('3'));
    }

    public function test_nothing_is_resolved_when_no_return_was_fully_scanned_and_other_stores_are_untouched(): void
    {
        $store = Store::factory()->create();
        $other = Store::factory()->create();
        $this->sync($store, [$this->row('1')], ['1']);
        $this->sync($other, [$this->row('1')], ['1']);

        $this->sync($store, [], []);
        $this->assertSame(IssueStatus::Open, $store->operationalIssues()->sole()->status);

        $this->sync($store, [], ['1']);
        $this->assertSame(IssueStatus::Resolved, $store->operationalIssues()->sole()->status);
        $this->assertSame(IssueStatus::Open, $other->operationalIssues()->sole()->status);
        $this->assertSame(2, OperationalIssue::query()->count());
    }
}
