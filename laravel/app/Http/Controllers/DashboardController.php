<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $reports = $store->auditSnapshots()->where('tool', 'run_audit')->latest('report_date')->limit(2)->get();
        $latest = $reports->first();

        return view('dashboard', [
            'latest' => $latest,
            'previousMissing' => $reports->get(1)?->rows_found,
            'totalReports' => $store->auditSnapshots()->where('tool', 'run_audit')->count(),
            'totalMissing' => (int) $store->auditSnapshots()->where('tool', 'run_audit')->sum('rows_found'),
            'ignoredCount' => $store->ignoredOrders()->count(),
            'pushesToday' => $store->pushLogs()->where('pushed_at', '>=', today())->count(),
            'pushesMonth' => $store->pushLogs()->where('pushed_at', '>=', now()->subDays(30))->count(),
            'missingOrders' => array_slice(is_array($latest?->result['missing'] ?? null) ? $latest->result['missing'] : [], 0, 10),
        ]);
    }
}
