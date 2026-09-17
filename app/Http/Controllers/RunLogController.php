<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class RunLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $store = $this->resolveStore($request);
        $q = trim((string) $request->string('q'));
        $query = $store->runLogs()->latest();
        if ($q !== '') {
            $query->where(fn ($sub) => $sub->where('tool', 'like', "%{$q}%")->orWhere('status', $q)->orWhere('error', 'like', "%{$q}%"));
        }

        return view('run-logs.index', ['runs' => $query->paginate(100)->withQueryString(), 'q' => $q]);
    }
}
