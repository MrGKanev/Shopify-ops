<?php

namespace App\Http\Controllers\Admin;

use App\Application\Health\CheckConfiguration;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConfigCheckController extends Controller
{
    public function __invoke(Request $request, CheckConfiguration $check): View
    {
        $store = $this->resolveStore($request);

        return view('admin.config-check', ['results' => $check->handle($store)]);
    }
}
