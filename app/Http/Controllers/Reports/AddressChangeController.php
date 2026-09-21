<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\AddressChangeResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunAddressChangeReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressChangeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AddressChangeController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.address-changes', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'configurationError' => false, 'reportFailed' => false]);
    }

    public function store(AddressChangeRequest $request, RunAddressChangeReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $start, $end);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Address change report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'address_changes', $started, $start, $end, count($result->rows ?? []), count($result->rows ?? []), $reportFailed);
        }

        return view('reports.address-changes', ['startDate' => $start, 'endDate' => $end, 'result' => $result instanceof AddressChangeResult ? $result : null, 'configurationError' => $configurationError, 'reportFailed' => $reportFailed]);
    }

    public function export(AddressChangeRequest $request, RunAddressChangeReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        $store = $this->resolveStore($request);
        if ($store->missingShopifyCredentials()) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        try {
            $result = $report->handle($store, $start, $end);
        } catch (Throwable $exception) {
            $this->logFailure('Address change CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['changed_at'], $row['gap_mins'], $row['email'], $row['addr_name'], $row['addr_line'], number_format((float) $row['total'], 2, '.', ''), $row['financial'], $row['fulfillment']], $result->rows);

        return $csv->download("address-changes-{$start}-to-{$end}.csv", ['Order', 'Placed', 'Changed', 'Time gap (minutes)', 'Email', 'Address name', 'Current shipping address', 'Total', 'Financial status', 'Fulfillment status'], $rows);
    }
}
