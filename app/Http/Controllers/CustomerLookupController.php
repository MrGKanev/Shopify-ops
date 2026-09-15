<?php

namespace App\Http\Controllers;

use App\Application\Reports\CustomerLookupResult;
use App\Application\Reports\RunCustomerLookup;
use App\Http\Requests\CustomerLookupRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class CustomerLookupController extends Controller
{
    public function create(Request $request): View
    {
        return view('customers.lookup', ['email' => trim($request->query('email', '')), 'result' => null, 'lookupFailed' => false, 'configurationError' => false]);
    }

    public function store(CustomerLookupRequest $request, RunCustomerLookup $lookup): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $email = mb_strtolower(trim((string) $request->validated('email')));
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        $result = null;
        $lookupFailed = false;
        if (! $configurationError) {
            try {
                $result = $lookup->handle($store, $email);
            } catch (Throwable $exception) {
                $lookupFailed = true;
                Log::warning('Customer lookup failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('customers.lookup', ['email' => $email, 'result' => $result instanceof CustomerLookupResult ? $result : null, 'lookupFailed' => $lookupFailed, 'configurationError' => $configurationError]);
    }
}
