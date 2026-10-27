<?php

namespace App\Http\Controllers;

use App\Application\Orders\LoadCustomerContext;
use App\Application\Orders\LoadPackingSlip;
use App\Http\Requests\PackingSlipRequest;
use App\Integrations\Exceptions\IntegrationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use LogicException;
use Throwable;

class PackingSlipController extends Controller
{
    public function create(Request $request): View
    {
        return view('orders.packing-slip', ['orderNumber' => is_string($request->query('order')) ? $request->query('order') : '', 'result' => null, 'lookupFailed' => false, 'configurationError' => false, 'customerContext' => null]);
    }

    public function store(PackingSlipRequest $request, LoadPackingSlip $loader, LoadCustomerContext $contexts): View
    {
        $store = $this->resolveStore($request);
        $number = (string) $request->validated('order_number');
        $result = null;
        $lookupFailed = false;
        $configurationError = false;
        $customerContext = null;
        try {
            $result = $loader->handle($store, $number);
            if ($request->boolean('customer_context') && $result['status'] === 'ready') {
                try {
                    $customerContext = $contexts->handle($store, [$number])['rows'][0] ?? null;
                } catch (Throwable) {
                    $customerContext = null;
                }
            }
        } catch (LogicException) {
            $configurationError = true;
        } catch (Throwable $exception) {
            $lookupFailed = true;
            Log::warning('Packing slip lookup failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof IntegrationException ? $exception->status : ($exception instanceof RequestException ? $exception->response->status() : null)]);
        }

        return view('orders.packing-slip', compact('result', 'lookupFailed', 'configurationError', 'customerContext') + ['orderNumber' => $number]);
    }
}
