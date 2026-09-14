<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ActiveStoreController extends Controller
{
    public function __invoke(Request $request, Store $store): RedirectResponse
    {
        $hasAccess = $request->user()
            ->stores()
            ->whereKey($store->getKey())
            ->exists();

        abort_unless($hasAccess, 404);

        $request->session()->put('active_store_id', $store->getKey());
        activity('operator-actions')->causedBy($request->user())->performedOn($store)
            ->withProperties(['store_id' => $store->getKey()])->log('switch_store');

        return redirect()->route('dashboard');
    }
}
