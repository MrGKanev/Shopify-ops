<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunBundleCheckReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarrierPerformanceRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BundleCheckController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.bundle-check', $this->viewData());
    }

    public function store(CarrierPerformanceRequest $request, RunBundleCheckReport $report, RecordRun $runs): View
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
                $this->logFailure('Bundle check report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'bundle_check', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.bundle-check', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError));
    }

    public function export(CarrierPerformanceRequest $request, RunBundleCheckReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }
        try {
            $result = $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $this->logFailure('Bundle check CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['order_type'], $row['missing_text'], $row['fulfillment_status'], $row['financial_status'], $row['email'], $row['total']], $result->rows);

        return $csv->download("bundle-check-{$startDate}-to-{$endDate}.csv", ['Order', 'Date', 'Type', 'Missing items', 'Fulfillment', 'Financial', 'Email', 'Total'], $rows);
    }

    /** @return array{Store, string, string} */
    private function context(CarrierPerformanceRequest $request): array
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
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString(), 'description' => config('order-types.bundle_check_description'), 'ruleNames' => array_column(array_filter(config('order-types.rules', []), fn (mixed $rule): bool => is_array($rule) && ($rule['required_items'] ?? []) !== []), 'name')];
    }
}
