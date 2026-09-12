<?php

namespace App\Http\Controllers;

use App\Application\Exports\CsvExporter;
use App\Models\AuditSnapshot;
use App\Models\Store;
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

    public function show(Request $request, int $report): View
    {
        return view('saved-reports.show', ['report' => $this->report($request, $report)]);
    }

    public function export(Request $request, int $report, CsvExporter $csv): StreamedResponse
    {
        $snapshot = $this->report($request, $report);
        $rows = array_map(fn (array $order): array => [$order['name'] ?? $order['order_number'] ?? '', $order['created_at'] ?? '', $order['email'] ?? '', (float) ($order['total_price'] ?? 0)], $snapshot->result['missing'] ?? []);

        return $csv->download("run-audit-{$snapshot->report_date->toDateString()}.csv", ['Order', 'Date', 'Email', 'Total'], $rows);
    }

    private function report(Request $request, int $id): AuditSnapshot
    {
        return $this->store($request)->auditSnapshots()->findOrFail($id);
    }

    private function store(Request $request): Store
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');

        return $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
    }
}
