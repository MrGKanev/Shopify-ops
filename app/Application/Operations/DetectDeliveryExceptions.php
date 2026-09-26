<?php

namespace App\Application\Operations;

use App\Domain\Reports\DeliveryExceptionAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;

class DetectDeliveryExceptions
{
    public function __construct(
        private readonly ShipStationClientFactory $clients,
        private readonly DeliveryExceptionAnalyzer $analyzer,
    ) {}

    public function handle(Store $store): int
    {
        if ($store->missingShipStationCredentials()) {
            return 0;
        }

        $threshold = max(1, min(90, (int) $store->delivery_watch_days));
        $client = $this->clients->forStore($store);
        if ($client === null) {
            return 0;
        }

        $shipments = $client->fetchShipmentsByDate(now()->subDays(max(30, $threshold + 7))->toDateString(), now()->toDateString());
        $exceptions = $this->analyzer->analyze($shipments, $threshold, now()->timestamp);

        foreach ($exceptions as $exception) {
            $issue = $store->operationalIssues()->firstOrNew(['fingerprint' => $exception['fingerprint']]);
            if ($exception['resolved']) {
                if ($issue->exists && in_array($issue->status, ['open', 'in_progress'], true)) {
                    $issue->update(['status' => 'resolved', 'resolved_at' => now(), 'last_seen_at' => now()]);
                }

                continue;
            }

            $newOccurrence = ! $issue->exists || $issue->last_seen_at->lt(now()->subDay());
            $issue->fill([
                'source_tool' => 'delivery_watch',
                'reference' => $exception['reference'],
                'title' => $exception['title'].' ('.$exception['days'].' days)',
                'status' => $issue->exists && in_array($issue->status, ['resolved', 'ignored'], true) ? 'open' : ($issue->status ?: 'open'),
                'priority' => $exception['priority'],
                'occurrences' => $issue->exists ? $issue->occurrences + (int) $newOccurrence : 1,
                'first_seen_at' => $issue->first_seen_at ?? now(),
                'last_seen_at' => now(),
                'resolved_at' => null,
                'payload' => $exception['payload'],
            ])->save();
        }

        return count(array_filter($exceptions, fn (array $exception): bool => ! $exception['resolved']));
    }
}
