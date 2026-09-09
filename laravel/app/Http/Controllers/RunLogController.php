<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RunLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();

        return view('run-logs.index', ['runs' => $store->runLogs()->latest()->paginate(100)]);
    }
}
