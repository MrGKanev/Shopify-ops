<?php

namespace App\Application\Operations;

use App\IssueStatus;
use App\Models\Store;

class SyncAuditIssues
{
    public function __construct(private readonly RaiseOperationalIssue $issues) {}

    /**
     * @param  list<array<string, mixed>>  $missingOrders
     * @param  list<array<string, mixed>>  $confirmedFoundOrders  Complete-scan orders matched by order number.
     */
    public function handle(Store $store, array $missingOrders, array $confirmedFoundOrders = []): void
    {
        $seenFingerprints = [];

        foreach ($missingOrders as $order) {
            $reference = trim((string) ($order['name'] ?? $order['order_number'] ?? ''));
            if ($reference === '') {
                continue;
            }

            $fingerprint = RaiseOperationalIssue::fingerprint('run_audit', ltrim($reference, '#'));
            $seenFingerprints[] = $fingerprint;
            $this->issues->handle($store, [
                'source_tool' => 'run_audit',
                'fingerprint' => $fingerprint,
                'title' => "Missing order {$reference}",
                'reference' => $reference,
                'priority' => (float) ($order['total_price'] ?? 0) >= 500 ? 'high' : 'normal',
                'payload' => $order,
            ]);
        }

        $recoveredFingerprints = [];
        foreach ($confirmedFoundOrders as $order) {
            $reference = trim((string) ($order['name'] ?? $order['order_number'] ?? ''));
            $matches = $order['shipstation_matches'] ?? [];
            if ($reference !== '' && ($order['match_method'] ?? null) === 'order_number' && count($matches) === 1
                && ltrim(trim((string) ($matches[0]['orderNumber'] ?? '')), '#') === ltrim($reference, '#')) {
                $recoveredFingerprints[] = RaiseOperationalIssue::fingerprint('run_audit', ltrim($reference, '#'));
            }
        }
        if ($recoveredFingerprints === []) {
            return;
        }
        foreach ($store->operationalIssues()->where('source_tool', 'run_audit')->whereIn('status', IssueStatus::active())
            ->whereIn('fingerprint', $recoveredFingerprints)->when($seenFingerprints !== [], fn ($query) => $query->whereNotIn('fingerprint', $seenFingerprints))->lazyById() as $issue) {
            $this->issues->resolve($issue);
        }
    }
}
