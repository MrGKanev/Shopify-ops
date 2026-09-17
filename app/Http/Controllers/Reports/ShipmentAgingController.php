<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunShipmentAgingReport;
use App\Application\Reports\ShipmentAgingResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShipmentAgingRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ShipmentAgingController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.shipment-aging', $this->viewData());
    }

    public function store(ShipmentAgingRequest $request, RunShipmentAgingReport $report, RecordRun $runs): View
    {
        [$store,$threshold] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $threshold);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Shipment aging report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'shipment_aging', $started, null, null, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.shipment-aging', $this->viewData($threshold, $result, $reportFailed, $configurationError));
    }

    public function export(ShipmentAgingRequest $request, RunShipmentAgingReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store,$threshold] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'ShipStation credentials are incomplete for the active store.']);
        }
        try {
            $result = $report->handle($store, $threshold);
        } catch (Throwable $exception) {
            $this->logFailure('Shipment aging CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['order_date'], $row['days'], $row['customer'], $row['email'], $row['total'], $row['order_type'], implode('; ', array_map(fn (string $sku, int $qty): string => $sku.' ×'.$qty, array_keys($row['skus']), $row['skus']))], $result->rows);

        return $csv->download('shipment-aging-'.now()->toDateString().'.csv', ['Order', 'Date', 'Days', 'Customer', 'Email', 'Total', 'Type', 'SKUs'], $rows);
    }

    /** @return array{Store,int} */
    private function context(ShipmentAgingRequest $request): array
    {

        return [$this->resolveStore($request), (int) $request->validated('threshold')];
    }

    private function configurationError(Store $store): bool
    {
        return $store->missingShipStationCredentials();
    }

    /** @return array<string,mixed> */
    private function viewData(int $threshold = 3, ?ShipmentAgingResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('threshold', 'result', 'reportFailed', 'configurationError');
    }
}
