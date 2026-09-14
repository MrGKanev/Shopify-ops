<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PushLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $q = trim((string) $request->string('q'));
        $query = $store->pushLogs()->latest('pushed_at')->latest('id');
        if ($q !== '') {
            $query->where(fn ($sub) => $sub->where('order_number', 'like', "%{$q}%")->orWhere('shopify_id', $q)->orWhere('shipstation_order_id', $q));
        }

        return view('push-logs.index', ['pushes' => $query->paginate(100)->withQueryString(), 'q' => $q]);
    }
}
