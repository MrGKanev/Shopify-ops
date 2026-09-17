<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\OrphanOrderResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunOrphanOrderReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrphanOrderRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OrphanOrderController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.orphan-orders', $this->viewData());
    }

    public function store(OrphanOrderRequest $request, RunOrphanOrderReport $report, RecordRun $runs): View
    {
        [$store,$start,$end] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $start, $end);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Orphan order report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'orphan_orders', $started, $start, $end, $result->shopifyTotal ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.orphan-orders', $this->viewData($start, $end, $result, $reportFailed, $configurationError));
    }

    public function export(OrphanOrderRequest $request, RunOrphanOrderReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store,$start,$end] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify and ShipStation credentials are required.']);
        }try {
            $result = $report->handle($store, $start, $end);
        } catch (Throwable $exception) {
            $this->logFailure('Orphan order CSV failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }$rows = array_map(fn (array $row): array => [$row['order_number'], $row['order_date'], $row['customer'], $row['email'], $row['order_status'], $row['total'], $row['ss_order_id']], $result->rows);

        return $csv->download("orphan-orders-{$start}-to-{$end}.csv", ['Order', 'Date', 'Customer', 'Email', 'ShipStation status', 'Total', 'ShipStation ID'], $rows);
    }

    /** @return array{Store,string,string} */
    private function context(OrphanOrderRequest $request): array
    {

        return [$this->resolveStore($request), (string) $request->validated('start_date'), (string) $request->validated('end_date')];
    }

    private function configurationError(Store $store): bool
    {
        return $store->missingShopifyCredentials() || $store->missingShipStationCredentials();
    }

    /** @return array<string,mixed> */
    private function viewData(?string $start = null, ?string $end = null, ?OrphanOrderResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $start ?? now()->subDays(30)->toDateString(), 'endDate' => $end ?? now()->toDateString()];
    }
}
