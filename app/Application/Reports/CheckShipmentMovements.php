<?php

namespace App\Application\Reports;

use App\Application\Operations\RaiseOperationalIssue;
use App\Domain\Reports\ShipmentMovementAnalyzer;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationTrackingClient;
use App\Models\ShipmentMovementObservation;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CheckShipmentMovements
{
    public function __construct(private readonly ShipStationTrackingClient $tracking, private readonly ShipmentMovementAnalyzer $analyzer, private readonly RaiseOperationalIssue $issues) {}

    /**
     * @param  list<array<string, mixed>>  $shipments  Store ownership must be confirmed against the parent order before calling.
     * @return ReportResult<array<string, mixed>>
     */
    public function handle(Store $store, array $shipments, int $acceptanceHours, int $staleHours, bool $requireVerifiedAccess = false): ReportResult
    {
        $labels = [];
        foreach ($shipments as $shipment) {
            $id = (string) ($shipment['shipmentId'] ?? '');
            if (! ctype_digit($id) || (int) $id < 1 || ! is_string($shipment['orderNumber'] ?? null) || trim($shipment['orderNumber']) === '' || (isset($labels[$id]) && $labels[$id] !== $shipment)) {
                throw new UnexpectedResponse('Shipment identity could not be confirmed.');
            }
            $labels[$id] = $shipment;
        }
        if (count($labels) > 10) {
            throw new UnexpectedResponse('Movement checks support at most ten labels per order.');
        }
        $observations = [];
        $active = [];
        $trackingNumbers = [];
        $voided = [];
        foreach ($labels as $id => $shipment) {
            if (! empty($shipment['voidDate']) || ($shipment['voided'] ?? false) === true) {
                $voided[] = RaiseOperationalIssue::fingerprint('shipment_movement', ShipmentMovementObservation::shipmentIdentity($store, $shipment));

                continue;
            }
            $carrier = $shipment['carrierCode'] ?? null;
            $number = $shipment['trackingNumber'] ?? null;
            if (! is_string($carrier) || ! preg_match('/^[a-z0-9_]+$/D', $carrier) || ! is_string($number) || trim($number) === '' || mb_strlen($number) > 255) {
                throw new UnexpectedResponse('A shipment carrier and tracking number are required.');
            }
            $trackingIdentity = $carrier.'|'.$number;
            if (isset($trackingNumbers[$trackingIdentity])) {
                throw new UnexpectedResponse('A tracking number is assigned to multiple active labels.');
            }
            $trackingNumbers[$trackingIdentity] = true;
            $active[] = $shipment;
        }
        foreach ($active as $shipment) {
            $observations[] = ['shipment' => $shipment, 'tracking' => $this->tracking->track($store, $shipment['carrierCode'], $shipment['trackingNumber'], $requireVerifiedAccess)];
        }
        $capturedAt = CarbonImmutable::now('UTC');
        $rows = [];
        foreach ($observations as $observation) {
            $shipment = $observation['shipment'];
            $analysis = $this->analyzer->analyze($observation['tracking'], is_string($shipment['createDate'] ?? null) ? $shipment['createDate'] : null, $acceptanceHours, $staleHours, $capturedAt);
            $identity = ShipmentMovementObservation::shipmentIdentity($store, $shipment);
            $rows[] = [...$analysis, 'identity' => $identity, 'fingerprint' => RaiseOperationalIssue::fingerprint('shipment_movement', $identity), 'shipment_id' => (string) $shipment['shipmentId'], 'order_number' => $shipment['orderNumber'], 'tracking_number' => $shipment['trackingNumber'], 'carrier' => $shipment['carrierCode'], 'source_status' => $observation['tracking']['status_code'], 'captured_at' => $capturedAt->toIso8601String(), 'acceptance_hours' => $acceptanceHours, 'stale_hours' => $staleHours];
        }
        usort($rows, fn (array $a, array $b): int => $a['identity'] <=> $b['identity']);
        DB::transaction(function () use ($store, $rows, $voided): void {
            foreach ($voided as $fingerprint) {
                $issue = $store->operationalIssues()->where('source_tool', 'shipment_movement')->where('fingerprint', $fingerprint)->first();
                if ($issue !== null) {
                    $this->issues->resolve($issue);
                }
            }
            foreach ($rows as $row) {
                $observation = $store->shipmentMovementObservations()->firstOrCreate(['identity' => $row['identity']], ['account_fingerprint' => ShipmentMovementObservation::accountFingerprint($store), 'shipment_id' => $row['shipment_id'], 'order_number' => $row['order_number'], 'carrier' => $row['carrier'], 'tracking_number' => $row['tracking_number'], 'classification' => $row['classification'], 'checked_at' => $row['captured_at']]);
                $observation = $store->shipmentMovementObservations()->whereKey($observation->id)->lockForUpdate()->firstOrFail();
                if ($observation->checked_at->gt(CarbonImmutable::parse($row['captured_at']))) {
                    continue;
                }
                $acceptance = $row['carrier_acceptance_at'] === null ? null : CarbonImmutable::parse($row['carrier_acceptance_at'])->utc();
                if ($acceptance !== null && ($observation->carrier_acceptance_at === null || $acceptance->lt($observation->carrier_acceptance_at))) {
                    $observation->carrier_acceptance_at = $acceptance;
                }
                $observation->fill(['classification' => $row['classification'], 'checked_at' => $row['captured_at']]);
                if ($row['last_progress_at'] !== null && $row['classification'] !== 'movement_unknown') {
                    $observation->last_progress_at = CarbonImmutable::parse($row['last_progress_at'])->utc();
                }
                $observation->save();
                if ($row['actionable']) {
                    $this->issues->handle($store, ['source_tool' => 'shipment_movement', 'fingerprint' => $row['fingerprint'], 'reference' => $row['order_number'], 'title' => 'Shipment movement needs review', 'priority' => in_array($row['classification'], ['carrier_exception', 'delivery_attempt'], true) ? 'high' : 'normal', 'payload' => $row], reopenIgnored: false, countOncePerDay: true);
                } elseif ($row['resolves']) {
                    $issue = $store->operationalIssues()->where('source_tool', 'shipment_movement')->where('fingerprint', $row['fingerprint'])->first();
                    if ($issue !== null) {
                        $this->issues->resolve($issue);
                    }
                }
            }
        });

        return new ReportResult(rows: $rows, scanned: count($labels), params: compact('acceptanceHours', 'staleHours'), meta: ['captured_at' => $capturedAt->toIso8601String(), 'exceptions' => count(array_filter($rows, fn (array $row): bool => $row['actionable']))]);
    }
}
