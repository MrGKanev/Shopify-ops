<?php

namespace App\Jobs;

use App\Application\Orders\CaptureRateQuotes;
use App\Application\Orders\LoadOrderForRemediation;
use App\Domain\Orders\RateComparisonUnavailable;
use App\Domain\Orders\ShipStationOrderUpdatePayload;
use App\Models\RateQuoteSnapshot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ApplyRateQuoteSelection implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $snapshotId) {}

    public function handle(CaptureRateQuotes $capture, LoadOrderForRemediation $orders): void
    {
        $snapshot = RateQuoteSnapshot::with(['store', 'user'])->findOrFail($this->snapshotId);
        if ($snapshot->status !== 'selection_queued') {
            return;
        }
        Cache::lock('rate-selection:'.$snapshot->store_id.':'.$snapshot->shipstation_order_id, 330)->block(5, function () use ($capture, $orders, $snapshot): void {
            if (RateQuoteSnapshot::whereKey($snapshot->id)->where('status', 'selection_queued')->update(['status' => 'selecting']) !== 1) {
                return;
            }
            try {
                if ($snapshot->user === null || ! Gate::forUser($snapshot->user)->allows('run-audits') || ! $snapshot->user->stores()->whereKey($snapshot->store_id)->exists()) {
                    throw new RateComparisonUnavailable('The operator no longer has access to this store.');
                }
                if ($snapshot->quoted_at === null || $snapshot->quoted_at->lt(now()->subMinutes(5))) {
                    throw new RateComparisonUnavailable('Quotes expired. Capture current rates before selecting a service.');
                }
                $context = $capture->context($snapshot->store, $snapshot->input);
                if (! hash_equals($snapshot->signature, CaptureRateQuotes::fingerprint($context))) {
                    throw new RateComparisonUnavailable('Shipping conditions changed since capture. Request a new snapshot.');
                }
                $order = $context['source_order'];
                if (! in_array($order['orderStatus'] ?? '', ['awaiting_payment', 'awaiting_shipment', 'on_hold'], true) || empty($order['orderKey'])) {
                    throw new RateComparisonUnavailable('Only open ShipStation orders with a stable orderKey can accept a service selection.');
                }
                if (! empty($order['shipDate']) && substr($order['shipDate'], 0, 10) !== $context['pricing_date']) {
                    throw new RateComparisonUnavailable('ShipStation V1 cannot price a different planned ship date. Update the date and capture new rates.');
                }
                $eligible = array_find($snapshot->quoteRows(), fn (array $quote): bool => $quote['eligible'] && $quote['service_code'] === $snapshot->selected_service);
                if ($eligible === null) {
                    throw new RateComparisonUnavailable('The selected service is not an approved quoted option.');
                }
                $advanced = $order['advancedOptions'] ?? [];
                $advanced['warehouseId'] = $context['warehouse_id'];
                $payload = ShipStationOrderUpdatePayload::build($order, ['carrierCode' => $context['carrier'], 'serviceCode' => $snapshot->selected_service, 'weight' => $context['weight'], 'dimensions' => $context['dimensions'], 'packageCode' => $context['package_code'], 'confirmation' => $context['confirmation'], 'shipTo' => $context['to'], 'advancedOptions' => $advanced]);
                activity('operator-actions')->causedBy($snapshot->user)->performedOn($snapshot)->withProperties(['service_code' => $snapshot->selected_service])->log('rate_selection_started');
                $client = $orders->client($snapshot->store);
                $result = $client->updateOrder($payload);
                if ((string) ($result['orderId'] ?? '') !== (string) $order['orderId']) {
                    throw new RateComparisonUnavailable('ShipStation did not confirm the expected order. Review its state before retrying.');
                }
                $actual = $client->getOrder((int) $order['orderId']);
                foreach (['carrierCode', 'serviceCode', 'packageCode', 'confirmation', 'orderKey', 'orderStatus'] as $field) {
                    if (($actual[$field] ?? null) !== $payload[$field]) {
                        throw new RateComparisonUnavailable('ShipStation did not confirm the selected service and shipping conditions.');
                    }
                }
                if ((float) ($actual['weight']['value'] ?? 0) !== $context['weight']['value'] || ($actual['weight']['units'] ?? '') !== $context['weight']['units']
                    || ($actual['dimensions']['units'] ?? '') !== $context['dimensions']['units']
                    || (string) ($actual['advancedOptions']['warehouseId'] ?? '') !== (string) $context['warehouse_id']) {
                    throw new RateComparisonUnavailable('ShipStation parcel settings differ after selection.');
                }
                $capture->requireComparable($actual, $context['from'], $actual['shipTo'] ?? []);
                foreach (['length', 'width', 'height'] as $field) {
                    if ((float) ($actual['dimensions'][$field] ?? 0) !== $context['dimensions'][$field]) {
                        throw new RateComparisonUnavailable('ShipStation parcel dimensions differ after selection.');
                    }
                }
                foreach (['street1', 'street2', 'street3', 'city', 'state', 'postalCode', 'country', 'residential'] as $field) {
                    if (($actual['shipTo'][$field] ?? null) !== ($context['to'][$field] ?? null)) {
                        throw new RateComparisonUnavailable('ShipStation destination conditions differ after selection.');
                    }
                }
                $snapshot->update(['status' => 'selected', 'mode' => 'recorded_decision', 'selected_at' => now(), 'message' => 'Service selection confirmed. These are captured quotes, not evidence of label purchase or invoiced savings.']);
                activity('operator-actions')->causedBy($snapshot->user)->performedOn($snapshot)->withProperties(['service_code' => $snapshot->selected_service, 'quoted_at' => $snapshot->quoted_at->toIso8601String()])->log('rate_selection_confirmed');
            } catch (Throwable $exception) {
                $this->failed($exception);
                $this->fail($exception);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        RateQuoteSnapshot::whereKey($this->snapshotId)->whereIn('status', ['selection_queued', 'selecting'])->update(['status' => 'failed', 'error_category' => $exception === null ? 'unknown' : $exception::class, 'message' => $exception instanceof RateComparisonUnavailable ? $exception->getMessage() : 'Selection could not be confirmed. Review ShipStation before retrying; a change may already have been applied.']);
    }
}
