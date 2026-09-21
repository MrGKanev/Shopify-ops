<?php

namespace App\Application\Operations;

use App\Models\Store;

class SyncAuditIssues
{
    /**
     * @param  list<array<string, mixed>>  $missingOrders
     */
    public function handle(Store $store, array $missingOrders): void
    {
        $seenFingerprints = [];

        foreach ($missingOrders as $order) {
            $reference = trim((string) ($order['name'] ?? $order['order_number'] ?? ''));
            if ($reference === '') {
                continue;
            }

            $fingerprint = hash('sha256', 'run_audit|'.ltrim($reference, '#'));
            $seenFingerprints[] = $fingerprint;
            $priority = (float) ($order['total_price'] ?? 0) >= 500 ? 'high' : 'normal';
            $issue = $store->operationalIssues()->firstOrNew(['fingerprint' => $fingerprint]);
            $issue->fill([
                'source_tool' => 'run_audit',
                'title' => "Missing order {$reference}",
                'reference' => $reference,
                'priority' => $priority,
                'status' => $issue->exists && in_array($issue->status, ['resolved', 'ignored'], true) ? 'open' : ($issue->status ?: 'open'),
                'occurrences' => $issue->exists ? $issue->occurrences + 1 : 1,
                'first_seen_at' => $issue->first_seen_at ?? now(),
                'last_seen_at' => now(),
                'resolved_at' => null,
                'payload' => $order,
            ]);
            $issue->save();
        }

        $store->operationalIssues()
            ->where('source_tool', 'run_audit')
            ->whereIn('status', ['open', 'in_progress'])
            ->when($seenFingerprints !== [], fn ($query) => $query->whereNotIn('fingerprint', $seenFingerprints))
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }
}
