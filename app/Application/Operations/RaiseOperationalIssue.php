<?php

namespace App\Application\Operations;

use App\IssuePriority;
use App\IssueStatus;
use App\Models\OperationalIssue;
use App\Models\Store;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates, refreshes, reopens, and auto-resolves store-scoped operational issues.
 *
 * Every detector (audits, anomalies, delivery watch, webhooks) records issues through this
 * class so reopen and occurrence rules stay identical across sources.
 */
class RaiseOperationalIssue
{
    public static function fingerprint(string $sourceTool, string $reference): string
    {
        return hash('sha256', $sourceTool.'|'.$reference);
    }

    /**
     * Record a new sighting of an issue.
     *
     * A resolved issue is reopened. An ignored issue is reopened only when `$reopenIgnored` is true.
     * In-progress issues keep their status. With `$countOncePerDay`, repeated sightings within a day
     * refresh the issue without increasing its occurrence count.
     *
     * @param  array{source_tool: string, fingerprint: string, title: string, priority: IssuePriority|string, reference?: string|null, payload?: array<string, mixed>|null}  $attributes
     */
    public function handle(Store $store, array $attributes, bool $reopenIgnored = true, bool $countOncePerDay = false): OperationalIssue
    {
        $issue = $store->operationalIssues()->firstOrNew(['fingerprint' => $attributes['fingerprint']]);
        $reopenedStatuses = $reopenIgnored ? [IssueStatus::Resolved, IssueStatus::Ignored] : [IssueStatus::Resolved];
        $isNewOccurrence = ! $issue->exists || ! $countOncePerDay || $issue->last_seen_at->lt(now()->subDay());

        $issue->fill([
            ...$attributes,
            'status' => ! $issue->exists || in_array($issue->status, $reopenedStatuses, true) ? IssueStatus::Open : $issue->status,
            'occurrences' => $issue->exists ? $issue->occurrences + (int) $isNewOccurrence : 1,
            'first_seen_at' => $issue->first_seen_at ?? now(),
            'last_seen_at' => now(),
            'resolved_at' => null,
        ]);

        try {
            $issue->save();
        } catch (UniqueConstraintViolationException) {
            return $this->handle($store, $attributes, $reopenIgnored, $countOncePerDay);
        }

        return $issue;
    }

    /**
     * Resolve open or in-progress issues from one source that were not seen in the latest scan.
     *
     * @param  list<string>  $activeFingerprints
     */
    public function resolveStale(Store $store, string $sourceTool, array $activeFingerprints): int
    {
        return $store->operationalIssues()
            ->where('source_tool', $sourceTool)
            ->whereIn('status', IssueStatus::active())
            ->when($activeFingerprints !== [], fn ($query) => $query->whereNotIn('fingerprint', $activeFingerprints))
            ->update(['status' => IssueStatus::Resolved, 'resolved_at' => now()]);
    }

    public function resolve(OperationalIssue $issue): void
    {
        if ($issue->status->isActive()) {
            $issue->update(['status' => IssueStatus::Resolved, 'resolved_at' => now(), 'last_seen_at' => now()]);
        }
    }
}
