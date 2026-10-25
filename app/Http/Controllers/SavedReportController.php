<?php

namespace App\Http\Controllers;

use App\Application\Exports\CsvExporter;
use App\Models\AuditSnapshot;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SavedReportController extends Controller
{
    public function index(Request $request): View
    {
        $store = $this->store($request);

        return view('saved-reports.index', ['reports' => $store->auditSnapshots()->latest('updated_at')->paginate(100)]);
    }

    public function show(Request $request, int $report): View|RedirectResponse
    {
        $snapshot = $this->report($request, $report);
        if (in_array($snapshot->tool, ['shipment_backlog', 'shopify_backlog'], true)) {
            return to_route('report-trends.index', ['start_date' => $snapshot->report_date->toDateString(), 'end_date' => $snapshot->report_date->toDateString()]);
        }
        $store = $this->store($request);
        $history = $store->auditSnapshots()->where('tool', 'run_audit')->latest('report_date')->limit(30)->get()->reverse()->values();
        $recurrenceCounts = [];
        foreach ($history as $historical) {
            foreach ((array) ($historical->result['missing'] ?? []) as $order) {
                $number = ltrim((string) ($order['name'] ?? $order['order_number'] ?? ''), '#');
                if ($number !== '') {
                    $recurrenceCounts[$number] = ($recurrenceCounts[$number] ?? 0) + 1;
                }
            }
        }

        return view('saved-reports.show', ['report' => $snapshot, 'history' => $history, 'recurrenceCounts' => $recurrenceCounts]);
    }

    public function export(Request $request, int $report, CsvExporter $csv): StreamedResponse
    {
        $snapshot = $this->report($request, $report);
        if ($snapshot->tool === 'shopify_backlog') {
            $data = $snapshot->result;
            $rows = array_map(fn (array $row): array => [$snapshot->report_date->toDateString(), $data['captured_at'], $data['completed_at'], $data['timezone'], implode('; ', $data['scopes']), $row['order_number'], $row['order_id'], $row['fulfillment_order_id'], $row['status'], $row['remaining_units'], $row['currency'], $row['estimated_value'], $row['known_value'], $row['unpriced_units'], $row['taxes_included'] ? 'yes' : 'no'], $data['rows']);
            if ($rows === []) {
                $rows[] = [$snapshot->report_date->toDateString(), $data['captured_at'], $data['completed_at'], $data['timezone'], implode('; ', $data['scopes']), '', '', '', '', 0, '', '', '', 0, ''];
            }

            return $csv->download("shopify-backlog-{$snapshot->report_date->toDateString()}.csv", ['Date', 'Captured at', 'Completed at', 'Timezone', 'Access scopes', 'Order', 'Order ID', 'Fulfillment order ID', 'Status', 'Remaining shipping units', 'Currency', 'Estimated remaining merchandise value', 'Priced subtotal', 'Unpriced units', 'Source prices include tax'], $rows);
        }
        if ($snapshot->tool === 'shipment_backlog') {
            $data = $snapshot->result;
            $ages = array_map(fn (string $bucket): int => $data['age_buckets'][$bucket], ['under_1_day', '1_2_days', '3_6_days', '7_plus_days', 'unknown']);

            return $csv->download("shipment-backlog-{$snapshot->report_date->toDateString()}.csv", ['Date', 'Captured at', 'Completed at', 'Timezone', 'Awaiting shipment', 'On hold', 'Total', 'Under 24h', '1–2 days', '3–6 days', '7+ days', 'Unknown age'], [[$snapshot->report_date->toDateString(), $data['captured_at'], $data['completed_at'], $data['timezone'], $data['awaiting'], $data['on_hold'], $snapshot->rows_found, ...$ages]]);
        }
        $rows = array_map(fn (array $order): array => [$order['name'] ?? $order['order_number'] ?? '', $order['created_at'] ?? '', $order['email'] ?? '', (float) ($order['total_price'] ?? 0)], $snapshot->result['missing'] ?? []);

        return $csv->download("run-audit-{$snapshot->report_date->toDateString()}.csv", ['Order', 'Date', 'Email', 'Total'], $rows);
    }

    private function report(Request $request, int $id): AuditSnapshot
    {
        return $this->store($request)->auditSnapshots()->findOrFail($id);
    }

    private function store(Request $request): Store
    {

        return $this->resolveStore($request);
    }
}
