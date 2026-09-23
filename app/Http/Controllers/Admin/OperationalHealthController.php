<?php

namespace App\Http\Controllers\Admin;

use App\Application\Health\BuildOperationalHealth;
use App\Application\Health\CheckConfiguration;
use App\Http\Controllers\Concerns\BuildsDiagnosticsView;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperationalHealthController extends Controller
{
    use BuildsDiagnosticsView;

    public function __invoke(Request $request, BuildOperationalHealth $health, CheckConfiguration $check): View
    {
        $store = $this->resolveStore($request);

        return view('admin.diagnostics', $this->diagnosticsViewData($health, $check, $store, 'health'));
    }
}
