<?php

namespace App\Application\Reports;

use App\Integrations\Exceptions\TrackingReadLimitExceeded;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\ShipmentMovementObservation;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use LogicException;

class RunShipmentMovementMonitorReport
{
    public function __construct(private readonly ShipStationClientFactory $clients, private readonly CheckShipmentMovements $checker, private readonly TrackingReadBudget $budget) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store): ReportResult
    {
        return Cache::lock('shipment-movement-monitor:'.$store->id, 300)->block(1, fn (): ReportResult => $this->scan($store));
    }

    /** @return ReportResult<array<string, mixed>> */
    private function scan(Store $store): ReportResult
    {
        $policy = $store->movementPolicy();
        $storeNumber = (string) $store->store_number;
        if (! $policy['access_verified'] || $policy['carriers'] === [] || trim((string) $store->shipstation_v2_api_key) === '' || ! ctype_digit($storeNumber) || (int) $storeNumber < 1) {
            throw new LogicException('Verify V2 tracking access, carrier coverage and API costs in Store settings before monitoring.');
        }
        $remaining = $this->budget->remaining($store);
        if ($remaining === 0) {
            throw new TrackingReadLimitExceeded;
        }
        $client = $this->clients->forStore($store) ?? throw new LogicException('ShipStation is not configured.');
        $since = CarbonImmutable::now('America/Los_Angeles')->subDays($policy['lookback_days'])->startOfDay()->format('Y-m-d\\TH:i:s');
        $shipments = $client->recentMonitoringShipments((int) $storeNumber, $since);
        $labels = [];
        $skipped = 0;
        foreach ($shipments as $shipment) {
            $id = (string) ($shipment['shipmentId'] ?? '');
            $orderId = (string) ($shipment['orderId'] ?? '');
            if (! ctype_digit($id) || (int) $id < 1 || ! ctype_digit($orderId) || (int) $orderId < 1 || (isset($shipment['advancedOptions']['storeId']) && (string) $shipment['advancedOptions']['storeId'] !== $storeNumber) || (isset($labels[$id]) && $labels[$id] !== $shipment)) {
                throw new UnexpectedResponse('The monitoring cohort contains an unconfirmed shipment identity.');
            }
            if (isset($labels[$id])) {
                continue;
            }
            $labels[$id] = $shipment;
        }
        $observed = $store->shipmentMovementObservations()->where('account_fingerprint', ShipmentMovementObservation::accountFingerprint($store))->whereIn('shipment_id', array_keys($labels))->get()->keyBy('identity');
        $candidates = [];
        foreach ($labels as $shipment) {
            if (! empty($shipment['voidDate']) || ($shipment['voided'] ?? false) === true || ! in_array($shipment['carrierCode'] ?? null, $policy['carriers'], true)) {
                $skipped++;

                continue;
            }
            if (! is_string($shipment['trackingNumber'] ?? null) || trim($shipment['trackingNumber']) === '') {
                $skipped++;

                continue;
            }
            $identity = ShipmentMovementObservation::shipmentIdentity($store, $shipment);
            $observation = $observed->get($identity);
            if ($observation?->classification === 'delivered') {
                $skipped++;

                continue;
            }
            $candidates[] = ['shipment' => $shipment, 'checked' => $observation?->checked_at?->getTimestamp() ?? 0];
        }
        usort($candidates, fn (array $left, array $right): int => [$left['checked'], (string) $left['shipment']['shipmentId']] <=> [$right['checked'], (string) $right['shipment']['shipmentId']]);
        $selected = array_slice($candidates, 0, min($policy['max_labels'], $remaining));
        $orders = [];
        $verified = [];
        foreach ($selected as $candidate) {
            $shipment = $candidate['shipment'];
            $orderId = (string) $shipment['orderId'];
            $order = $orders[$orderId] ??= $client->getOrder((int) $orderId);
            if ((string) ($order['orderId'] ?? '') !== $orderId || (string) ($order['advancedOptions']['storeId'] ?? '') !== $storeNumber || ! is_string($order['orderNumber'] ?? null) || trim($order['orderNumber']) === '') {
                throw new UnexpectedResponse('A monitored shipment does not belong to the configured store.');
            }
            $verified[] = [...$shipment, 'orderNumber' => $order['orderNumber']];
        }
        $result = $this->checker->handle($store, $verified, $policy['acceptance_hours'], $policy['stale_hours'], requireVerifiedAccess: true);

        return new ReportResult(rows: array_values(array_filter($result->rows, fn (array $row): bool => $row['actionable'])), scanned: count($labels), truncated: count($selected) < count($candidates), meta: [...$result->meta, 'observations' => $result->rows, 'candidate_labels' => count($candidates), 'checked_labels' => count($selected), 'not_checked' => count($candidates) - count($selected), 'skipped_labels' => $skipped, 'budget_remaining' => $this->budget->remaining($store), 'lookback_days' => $policy['lookback_days']]);
    }
}
