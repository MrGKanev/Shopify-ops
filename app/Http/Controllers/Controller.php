<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function resolveStore(Request $request): Store
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');

        return $this->authenticatedUser($request)->stores()->whereKey($activeStore->getKey())->firstOrFail();
    }

    protected function authenticatedUser(Request $request): User
    {
        return $request->user() ?? abort(401);
    }
}
