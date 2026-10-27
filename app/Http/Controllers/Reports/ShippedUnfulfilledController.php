<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Orders\OperationalFieldExportColumns;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunShippedUnfulfilledReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Requests\DateRangeReportRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ShippedUnfulfilledController extends ReportController
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.shipped-unfulfilled', $this->viewData());
    }

    public function store(DateRangeReportRequest $request, RunShippedUnfulfilledReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        [$store,$start,$end] = $this->context($request);
        $configurationError = $this->configurationError($store);

        return $this->runQueued($reports, $request, $store, $configurationError, 'shipped_unfulfilled', $report::class, [$start, $end], $start, $end, fn (?ReportResult $result, bool $reportFailed) => view('reports.shipped-unfulfilled', $this->viewData($start, $end, $result, $reportFailed, $configurationError)));
    }

    public function export(DateRangeReportRequest $request, RunShippedUnfulfilledReport $report, CsvExporter $csv, QueuedReportRunner $reports, OperationalFieldExportColumns $operationalFields): StreamedResponse|RedirectResponse
    {
        [$store,$start,$end] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => __('reports.integration_credentials_required')]);
        }try {
            $result = $reports->completedResult($store, 'shipped_unfulfilled', $report::class, [$start, $end]) ?? $report->handle($store, $start, $end);
        } catch (Throwable $exception) {
            $this->logFailure('Shipped/unfulfilled CSV failed.', $exception, $store);

            return back()->withErrors(['export' => __('reports.export_failed')]);
        }
        $extra = $request->boolean('operational_fields') ? $operationalFields->handle($store, array_column($result->rows, 'order_number')) : ['headers' => [], 'cells' => [], 'fallback' => []];
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['order_date'], $row['customer'], $row['email'], 'shipped', $row['sh_fulfillment'], $row['sh_financial'], $row['total'], ...($extra['cells'][$row['order_number']] ?? $extra['fallback'])], $result->rows);

        return $csv->download("shipped-unfulfilled-{$start}-to-{$end}.csv", ['Order', 'Date', 'Customer', 'Email', 'ShipStation status', 'Shopify fulfillment', 'Shopify financial', 'Total', ...$extra['headers']], $rows);
    }

    /** @return array{Store,string,string} */
    private function context(DateRangeReportRequest $request): array
    {

        return [$this->resolveStore($request), (string) $request->validated('start_date'), (string) $request->validated('end_date')];
    }

    private function configurationError(Store $store): bool
    {
        return $store->missingShopifyCredentials() || $store->missingShipStationCredentials();
    }

    /**
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @param  ReportResult<array<string, mixed>>|null  $result
     * @return array<string,mixed>
     */
    private function viewData(?string $start = null, ?string $end = null, ?ReportResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $start ?? now()->subDays(30)->toDateString(), 'endDate' => $end ?? now()->toDateString()];
    }
}
