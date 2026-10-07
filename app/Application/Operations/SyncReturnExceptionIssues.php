<?php

namespace App\Application\Operations;

use App\IssueStatus;
use App\Models\Store;

class SyncReturnExceptionIssues
{
    public function __construct(private readonly RaiseOperationalIssue $issues) {}

    /** @param list<array<string, mixed>> $rows
     * @param list<string> $completeReturnIds */
    public function handle(Store $store, array $rows, array $completeReturnIds, string $startDate, string $endDate): void
    {
        $active = [];
        foreach ($rows as $row) {
            $fingerprint = RaiseOperationalIssue::fingerprint('return_exceptions', $row['return_id'].'|'.$row['kind']);
            $active[] = $fingerprint;
            $this->issues->handle($store, [
                'source_tool' => 'return_exceptions', 'fingerprint' => $fingerprint,
                'reference' => $row['return_id'], 'title' => $row['next_action'].' · '.$row['return_name'].' · '.$row['order_number'],
                'priority' => 'normal', 'payload' => [...$row, 'start_date' => $startDate, 'end_date' => $endDate],
            ], reopenIgnored: false, countOncePerDay: true);
        }
        if ($completeReturnIds === []) {
            return;
        }
        foreach ($store->operationalIssues()->where('source_tool', 'return_exceptions')->whereIn('reference', $completeReturnIds)
            ->whereIn('status', IssueStatus::active())->when($active !== [], fn ($query) => $query->whereNotIn('fingerprint', $active))->lazyById() as $issue) {
            $this->issues->resolve($issue);
        }
    }
}
