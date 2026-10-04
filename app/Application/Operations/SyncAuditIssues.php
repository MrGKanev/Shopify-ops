<?php

namespace App\Application\Operations;

use App\Models\Store;

class SyncAuditIssues
{
    public function __construct(private readonly RaiseOperationalIssue $issues) {}

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

        $this->issues->resolveStale($store, 'run_audit', $seenFingerprints);
    }
}
