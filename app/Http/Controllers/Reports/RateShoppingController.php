<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\RateShoppingAnalyzer;
use App\Http\Controllers\Controller;
use App\Http\Requests\RateQuoteRequest;
use App\Http\Requests\SelectRateQuoteRequest;
use App\Jobs\ApplyRateQuoteSelection;
use App\Jobs\CaptureRateQuoteSnapshot;
use App\Models\RateQuoteSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class RateShoppingController extends Controller
{
    public function create(Request $request): View
    {
        return view('reports.rate-shopping', ['snapshots' => $this->resolveStore($request)->rateQuoteSnapshots()->latest('id')->paginate(30, ['id', 'order_number', 'status', 'mode', 'quoted_at']), 'prefill' => is_string($request->input('order_number')) ? $request->input('order_number') : '']);
    }

    public function store(RateQuoteRequest $request): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $snapshot = $store->rateQuoteSnapshots()->create(['user_id' => $request->user()->id, 'order_number' => $request->validated('order_number'), 'input' => $request->validated(), 'status' => 'queued', 'mode' => 'simulation']);
        try {
            CaptureRateQuoteSnapshot::dispatch($snapshot->id);
        } catch (Throwable $exception) {
            $snapshot->refresh();
            if ($snapshot->status === 'queued') {
                $snapshot->update(['status' => 'failed', 'error_category' => $exception::class, 'message' => 'Rate capture could not be queued. Create a new request.']);
            }
        }

        return redirect()->route('reports.rate-shopping.result', $snapshot->id);
    }

    public function show(Request $request, int $snapshot, RateShoppingAnalyzer $analyzer): View
    {
        $snapshot = $this->resolveStore($request)->rateQuoteSnapshots()->findOrFail($snapshot);
        $reference = $snapshot->mode === 'recorded_decision' ? $snapshot->selected_service : data_get($snapshot->context, 'source_order.serviceCode');
        $comparison = $analyzer->compare($snapshot->quoteRows(), $reference);
        $shipDate = data_get($snapshot->context, 'source_order.shipDate');
        $dateCompatible = empty($shipDate) || (is_string($shipDate) && substr($shipDate, 0, 10) === data_get($snapshot->context, 'pricing_date'));
        $canSelect = $dateCompatible && $snapshot->status === 'ready' && $snapshot->user_id === $request->user()->id && $snapshot->quoted_at?->gte(now()->subMinutes(5))
            && count(array_filter($snapshot->quoteRows(), fn (array $quote): bool => $quote['eligible'])) > 0
            && in_array(data_get($snapshot->context, 'source_order.orderStatus'), ['awaiting_payment', 'awaiting_shipment', 'on_hold'], true);

        return view('reports.rate-shopping-snapshot', compact('snapshot', 'comparison', 'canSelect'));
    }

    public function select(SelectRateQuoteRequest $request, int $snapshot): RedirectResponse
    {
        $snapshot = $this->resolveStore($request)->rateQuoteSnapshots()->findOrFail($snapshot);
        abort_unless($snapshot->user_id === $request->user()->id, 404);
        abort_unless($snapshot->status === 'ready' && $snapshot->quoted_at?->gte(now()->subMinutes(5)), 409, 'Quotes expired or are no longer selectable.');
        $service = $request->validated('service_code');
        $eligible = array_find($snapshot->quotes ?? [], fn (array $quote): bool => $quote['eligible'] && $quote['service_code'] === $service);
        abort_if($eligible === null, 422, 'Select an approved quoted service.');
        abort_unless(in_array(data_get($snapshot->context, 'source_order.orderStatus'), ['awaiting_payment', 'awaiting_shipment', 'on_hold'], true), 409);
        if (RateQuoteSnapshot::whereKey($snapshot->id)->where('status', 'ready')->update(['status' => 'selection_queued', 'selected_service' => $service]) === 1) {
            try {
                ApplyRateQuoteSelection::dispatch($snapshot->id);
            } catch (Throwable $exception) {
                $snapshot->refresh();
                if ($snapshot->status === 'selection_queued') {
                    $snapshot->update(['status' => 'failed', 'error_category' => $exception::class, 'message' => 'Service selection could not be queued. Capture new quotes before retrying.']);
                }
            }
        }

        return redirect()->route('reports.rate-shopping.result', $snapshot->id);
    }
}
