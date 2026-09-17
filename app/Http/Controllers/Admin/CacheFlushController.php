<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CacheFlushController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $store = $this->resolveStore($request);
        Cache::flush();
        activity('operator-actions')->causedBy($request->user())->performedOn($store)
            ->withProperties(['store_id' => $store->getKey()])->log('flush_cache');

        return back()->with('status', 'Cache flushed.');
    }
}
