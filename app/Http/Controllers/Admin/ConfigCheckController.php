<?php

namespace App\Http\Controllers\Admin;

use App\Application\Health\CheckConfiguration;
use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConfigCheckController extends Controller
{
    public function __invoke(Request $request, CheckConfiguration $check): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();

        return view('admin.config-check', ['results' => $check->handle($store)]);
    }
}
