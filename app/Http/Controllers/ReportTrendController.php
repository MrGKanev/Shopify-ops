<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportTrendController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate(['start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date']]);
        $start = (string) ($validated['start_date'] ?? now()->subDays(30)->toDateString());
        $end = (string) ($validated['end_date'] ?? now()->toDateString());
        $store = $this->resolveStore($request);
        $snapshots = $store->auditSnapshots()->where('tool', 'run_audit')->whereDate('report_date', '>=', $start)->whereDate('report_date', '<=', $end)->oldest('report_date')->get();
        $previous = null;
        $rows = $snapshots->map(function ($snapshot) use (&$previous): array {
            $count = $snapshot->rows_found;
            $row = ['id' => $snapshot->getKey(), 'date' => $snapshot->report_date->toDateString(), 'missing' => $count, 'delta' => $previous === null ? null : $count - $previous];
            $previous = $count;

            return $row;
        })->all();
        $maximum = max(array_column($rows, 'missing') ?: [0]);

        $missingColumn = array_column($rows, 'missing');
        $avgMissing = $missingColumn === [] ? 0.0 : round(array_sum($missingColumn) / count($missingColumn), 1);
        $clearReportCount = $snapshots->where('rows_found', 0)->count();
        $worstReport = $snapshots->sortByDesc('rows_found')->first();

        $missingCounts = [];
        foreach ($snapshots as $snapshot) {
            foreach ((array) ($snapshot->result['missing'] ?? []) as $order) {
                $number = (string) ($order['name'] ?? $order['order_number'] ?? '');
                if ($number !== '') {
                    $missingCounts[$number] = ($missingCounts[$number] ?? 0) + 1;
                }
            }
        }
        arsort($missingCounts);
        $uniqueMissingCount = count($missingCounts);
        $repeatOffenders = collect($missingCounts)->filter(fn (int $count): bool => $count >= 2)->map(fn (int $count, string $number): array => ['number' => $number, 'count' => $count])->values()->all();

        return view('saved-reports.trends', compact('rows', 'maximum', 'avgMissing', 'clearReportCount', 'worstReport', 'uniqueMissingCount', 'repeatOffenders') + ['startDate' => $start, 'endDate' => $end]);
    }
}
