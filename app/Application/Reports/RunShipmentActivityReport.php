<?php

namespace App\Application\Reports;

use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\ShipmentMovementObservation;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

class RunShipmentActivityReport
{
    public function __construct(private readonly ShopifyOrders $shopify) {}

    public function assertPeriod(Store $store, string $startDate, string $endDate): void
    {
        $start = CarbonImmutable::parse($startDate, $store->shopTimezone())->startOfDay();
        $end = CarbonImmutable::parse($endDate, $store->shopTimezone())->endOfDay();
        if ($end->gte(CarbonImmutable::now($store->shopTimezone())->startOfDay()) || $end->lt($start) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['end_date' => __('Choose completed store-local days, up to 366 days per report.')]);
        }
    }

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate): ReportResult
    {
        $zone = $store->shopTimezone();
        $start = CarbonImmutable::parse($startDate, $zone)->startOfDay();
        $end = CarbonImmutable::parse($endDate, $zone)->endOfDay();
        $this->assertPeriod($store, $startDate, $endDate);
        $candidates = $this->shopify->shipmentArrivalCandidates($store, $startDate, $endDate);
        if ($candidates['truncated'] || $candidates['scopes'] === []) {
            throw new UnexpectedValueException('Shopify arrival coverage is incomplete.');
        }
        $created = $seen = [];
        foreach ($candidates['orders'] as $order) {
            $id = $order['id'] ?? null;
            $date = $order['createdAt'] ?? null;
            if (! is_string($id) || ! preg_match('~^gid://shopify/Order/[1-9][0-9]*$~D', $id) || isset($seen[$id]) || ! is_bool($order['test'] ?? null) || ! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $date)) {
                throw new UnexpectedValueException('Shopify arrival identity or creation date could not be confirmed.');
            }
            $instant = CarbonImmutable::parse($date);
            if ($instant->format('Y-m-d') !== substr($date, 0, 10) || $instant->lt($start) || $instant->gt($end)) {
                throw new UnexpectedValueException('Shopify returned an order outside the requested arrival period.');
            }
            $seen[$id] = true;
            if (! $order['test']) {
                $day = $instant->setTimezone($zone)->toDateString();
                $created[$day] = ($created[$day] ?? 0) + 1;
            }
        }
        $observations = $store->shipmentMovementObservations()->where('account_fingerprint', ShipmentMovementObservation::accountFingerprint($store));
        $observedAsOf = (clone $observations)->max('checked_at');
        $accepted = (clone $observations)->select('shipment_id')->selectRaw('MIN(carrier_acceptance_at) as accepted_at')->whereNotNull('carrier_acceptance_at')->groupBy('shipment_id')
            ->havingRaw('MIN(carrier_acceptance_at) >= ?', [$start->utc()->format('Y-m-d H:i:s')])->havingRaw('MIN(carrier_acceptance_at) <= ?', [$end->utc()->format('Y-m-d H:i:s')])->limit(10001)->get();
        if ($accepted->count() > 10000) {
            throw new UnexpectedValueException('Carrier acceptance coverage exceeds the report limit.');
        }
        $acceptances = [];
        foreach ($accepted as $observation) {
            $day = CarbonImmutable::parse((string) $observation->getAttribute('accepted_at'), 'UTC')->setTimezone($zone)->toDateString();
            $acceptances[$day] = ($acceptances[$day] ?? 0) + 1;
        }
        $rows = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $rows[] = ['date' => $date, 'created_orders' => $created[$date] ?? 0, 'recorded_carrier_acceptances' => $observedAsOf === null ? null : ($acceptances[$date] ?? 0)];
        }

        return new ReportResult(rows: $rows, scanned: count($candidates['orders']), pages: $candidates['pages'], params: compact('startDate', 'endDate'), meta: ['shopify_store' => (string) $store->shopify_store, 'shipstation_store_number' => (string) $store->store_number, 'timezone' => $zone, 'scopes' => $candidates['scopes'], 'tracking_observed_as_of' => $observedAsOf, 'carrier_coverage' => 'observed_labels_only', 'captured_at' => CarbonImmutable::now('UTC')->toIso8601String()]);
    }
}
