<?php

namespace App\Http\Controllers;

use App\Application\Orders\PushOrderToShipStation;
use App\Http\Requests\PushOrderToShipStationRequest;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            return response()->json(['payload' => $push->preview($this->activeStore($request), (string) $request->validated('order_number'))]);
        } catch (Throwable $exception) {
            Log::warning('Push to ShipStation preview failed.', ['exception_type' => $exception::class]);

            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function store(PushOrderToShipStationRequest $request, PushOrderToShipStation $push): RedirectResponse
    {
        $orderNumber = (string) $request->validated('order_number');

        try {
            $result = $push->handle($this->activeStore($request), $orderNumber);

            return back()->with('status', "Pushed order #{$result['order_number']} to ShipStation.");
        } catch (Throwable $exception) {
            Log::warning('Push to ShipStation failed.', ['exception_type' => $exception::class]);

            return back()->withErrors(['order_number' => $exception->getMessage()]);
        }
    }

    private function activeStore(Request $request): Store
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');

        return $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
    }
}
