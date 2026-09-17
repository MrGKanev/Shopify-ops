<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunOnHoldStallReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\OnHoldStallRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OnHoldStallController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.on-hold-stall', $this->viewData());
    }

    public function store(OnHoldStallRequest $request, RunOnHoldStallReport $report, RecordRun $runs): View
    {
        [$store, $startDate, $endDate] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('On-hold stall report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'on_hold_stall', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.on-hold-stall', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError));
    }

    public function export(OnHoldStallRequest $request, RunOnHoldStallReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }
        try {
            $result = $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $this->logFailure('On-hold stall CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['days_waiting'], $row['hold_reason'], $row['hold_notes'], $row['email'], $row['total'], $row['financial'], $row['fulfillment']], $result->rows);

        return $csv->download("on-hold-stalls-{$startDate}-to-{$endDate}.csv", ['Order', 'Placed', 'Days waiting', 'Hold reason', 'Notes', 'Email', 'Total', 'Financial status', 'Fulfillment status'], $rows);
    }

    /** @return array{Store, string, string} */
    private function context(OnHoldStallRequest $request): array
    {

        return [$this->resolveStore($request), (string) $request->validated('start_date'), (string) $request->validated('end_date')];
    }

    private function configurationError(Store $store): bool
    {
        return $store->missingShopifyCredentials();
    }

    /** @return array<string, mixed> */
    private function viewData(?string $startDate = null, ?string $endDate = null, ?ScanResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(90)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
