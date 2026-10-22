<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class RunLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $store = $this->resolveStore($request);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $q = trim((string) $request->string('q'));
        $query = $store->runLogs()->latest();
        if ($q !== '') {
            $query->where(fn ($sub) => $sub->where('tool', 'like', "%{$q}%")->orWhere('status', $q)->orWhere('error', 'like', "%{$q}%"));
        }

        $from = $filters['from'] ?? '';
        $to = $filters['to'] ?? '';
        if ($from !== '') {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to !== '') {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        return view('run-logs.index', ['runs' => $query->paginate(100)->withQueryString(), 'q' => $q, 'from' => $from, 'to' => $to]);
    }
}
