<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function resolveStore(Request $request): Store
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');

        return $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
    }
}
