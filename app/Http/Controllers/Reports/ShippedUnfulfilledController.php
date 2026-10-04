<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunShippedUnfulfilledReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ShippedUnfulfilledController extends Controller
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
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'shipped_unfulfilled', $report::class, [$start, $end], $start, $end);
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.shipped-unfulfilled', $this->viewData($start, $end, $result, $reportFailed, $configurationError));
    }

    public function export(DateRangeReportRequest $request, RunShippedUnfulfilledReport $report, CsvExporter $csv, QueuedReportRunner $reports): StreamedResponse|RedirectResponse
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
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['order_date'], $row['customer'], $row['email'], 'shipped', $row['sh_fulfillment'], $row['sh_financial'], $row['total']], $result->rows);

        return $csv->download("shipped-unfulfilled-{$start}-to-{$end}.csv", ['Order', 'Date', 'Customer', 'Email', 'ShipStation status', 'Shopify fulfillment', 'Shopify financial', 'Total'], $rows);
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
