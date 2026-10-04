<?php

namespace App\Http\Controllers;

use App\Application\Orders\PushOrderToShipStation;
use App\Application\Orders\ShippingAddressNeedsReview;
use App\Http\Requests\PushOrderToShipStationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class PushToShipStationController extends Controller
{
    public function create(): View
    {
        return view('orders.push');
    }

    public function preview(PushOrderToShipStationRequest $request, PushOrderToShipStation $push): JsonResponse
    {
        try {
            return response()->json($push->preview($this->resolveStore($request), (string) $request->validated('order_number')));
        } catch (Throwable $exception) {
            Log::warning('Push to ShipStation preview failed.', ['exception_type' => $exception::class]);

            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function store(PushOrderToShipStationRequest $request, PushOrderToShipStation $push): RedirectResponse
    {
        $orderNumber = (string) $request->validated('order_number');

        try {
            $store = $this->resolveStore($request);
            $result = $push->handle($store, $orderNumber, $request->boolean('confirm_address_issues'));
            activity('operator-actions')->causedBy($request->user())->performedOn($store)
                ->withProperties(['order_number' => $result['order_number']])->log('push_to_shipstation');

            return back()->with('status', __('Pushed order #:number to ShipStation.', ['number' => $result['order_number']]));
        } catch (ShippingAddressNeedsReview $exception) {
            return back()->withInput()->with('addressIssues', $exception->issues)->withErrors(['order_number' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::warning('Push to ShipStation failed.', ['exception_type' => $exception::class]);

            return back()->withErrors(['order_number' => $exception->getMessage()]);
        }
    }
}
