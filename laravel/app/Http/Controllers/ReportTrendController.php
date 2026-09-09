<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportTrendController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate(['start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date']]);
        $start = (string) ($validated['start_date'] ?? now()->subDays(30)->toDateString());
        $end = (string) ($validated['end_date'] ?? now()->toDateString());
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $snapshots = $store->auditSnapshots()->where('tool', 'run_audit')->whereDate('report_date', '>=', $start)->whereDate('report_date', '<=', $end)->oldest('report_date')->get();
        $previous = null;
        $rows = $snapshots->map(function ($snapshot) use (&$previous): array {
            $count = $snapshot->rows_found;
            $row = ['id' => $snapshot->getKey(), 'date' => $snapshot->report_date->toDateString(), 'missing' => $count, 'delta' => $previous === null ? null : $count - $previous];
            $previous = $count;

            return $row;
        })->all();
        $maximum = max(array_column($rows, 'missing') ?: [0]);

        return view('saved-reports.trends', compact('rows', 'maximum') + ['startDate' => $start, 'endDate' => $end]);
    }
}
