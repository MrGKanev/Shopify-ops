<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunActiveShipStationConflictReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ActiveShipStationConflictController extends Controller
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.active-shipstation-conflicts', $this->viewData());
    }

    public function store(DateRangeReportRequest $request, RunActiveShipStationConflictReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        [$store,$start,$end] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'active_shipstation_conflicts', $report::class, [$start, $end], $start, $end);
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.active-shipstation-conflicts', $this->viewData($start, $end, $result, $reportFailed, $configurationError));
    }

    public function export(DateRangeReportRequest $request, RunActiveShipStationConflictReport $report, CsvExporter $csv, QueuedReportRunner $reports): StreamedResponse|RedirectResponse
    {
        [$store,$start,$end] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => __('reports.integration_credentials_required')]);
        }try {
            $result = $reports->completedResult($store, 'active_shipstation_conflicts', $report::class, [$start, $end]) ?? $report->handle($store, $start, $end);
        } catch (Throwable $exception) {
            $this->logFailure('Active ShipStation conflicts CSV failed.', $exception, $store);

            return back()->withErrors(['export' => __('reports.export_failed')]);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['issue'], $row['created_at'], $row['email'], $row['total'], $row['ss_status'], $row['ss_date'], $row['ss_total']], $result->rows);

        return $csv->download("active-shipstation-conflicts-{$start}-to-{$end}.csv", ['Order', 'Issue', 'Shopify date', 'Email', 'Shopify total', 'ShipStation status', 'ShipStation date', 'ShipStation total'], $rows);
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
