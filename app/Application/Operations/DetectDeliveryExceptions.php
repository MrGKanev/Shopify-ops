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
        private readonly RaiseOperationalIssue $issues,
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
            if ($exception['resolved']) {
                $issue = $store->operationalIssues()->where('fingerprint', $exception['fingerprint'])->first();
                if ($issue !== null) {
                    $this->issues->resolve($issue);
                }

                continue;
            }

            $this->issues->handle($store, [
                'source_tool' => 'delivery_watch',
                'fingerprint' => $exception['fingerprint'],
                'reference' => $exception['reference'],
                'title' => $exception['title'].' ('.$exception['days'].' days)',
                'priority' => $exception['priority'],
                'payload' => $exception['payload'],
            ], countOncePerDay: true);
        }

        return count(array_filter($exceptions, fn (array $exception): bool => ! $exception['resolved']));
    }
}
