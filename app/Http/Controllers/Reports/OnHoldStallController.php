<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Orders\OperationalFieldExportColumns;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunOnHoldStallReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Requests\DateRangeReportRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OnHoldStallController extends ReportController
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.on-hold-stall', $this->viewData());
    }

    public function store(DateRangeReportRequest $request, RunOnHoldStallReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        $configurationError = $this->configurationError($store);

        return $this->runQueued($reports, $request, $store, $configurationError, 'on_hold_stall', $report::class, [$startDate, $endDate], $startDate, $endDate, fn (?ReportResult $result, bool $reportFailed) => view('reports.on-hold-stall', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError)));
    }

    public function export(DateRangeReportRequest $request, RunOnHoldStallReport $report, CsvExporter $csv, QueuedReportRunner $reports, OperationalFieldExportColumns $operationalFields): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => __('reports.shopify_credentials_incomplete')]);
        }
        try {
            $result = $reports->completedResult($store, 'on_hold_stall', $report::class, [$startDate, $endDate]) ?? $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $this->logFailure('On-hold stall CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => __('reports.export_failed')]);
        }
        $extra = $request->boolean('operational_fields') ? $operationalFields->handle($store, array_column($result->rows, 'order_number')) : ['headers' => [], 'cells' => [], 'fallback' => []];
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['days_waiting'], $row['hold_reason'], $row['hold_notes'], $row['email'], $row['total'], $row['financial'], $row['fulfillment'], ...($extra['cells'][$row['order_number']] ?? $extra['fallback'])], $result->rows);

        return $csv->download("on-hold-stalls-{$startDate}-to-{$endDate}.csv", ['Order', 'Placed', 'Days waiting', 'Hold reason', 'Notes', 'Email', 'Total', 'Financial status', 'Fulfillment status', ...$extra['headers']], $rows);
    }

    /** @return array{Store, string, string} */
    private function context(DateRangeReportRequest $request): array
    {

        return [$this->resolveStore($request), (string) $request->validated('start_date'), (string) $request->validated('end_date')];
    }

    private function configurationError(Store $store): bool
    {
        return $store->missingShopifyCredentials();
    }

    /**
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @return array<string, mixed>
     */
    private function viewData(?string $startDate = null, ?string $endDate = null, ?ReportResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(90)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
