<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\AddressChangeResult;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunAddressChangeReport;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AddressChangeController extends Controller
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.address-changes', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'configurationError' => false, 'reportFailed' => false]);
    }

    public function store(DateRangeReportRequest $request, RunAddressChangeReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        $configurationError = $store->missingShopifyCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'address_changes', $report::class, [$start, $end], $start, $end, 'count:rows', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.address-changes', ['startDate' => $start, 'endDate' => $end, 'result' => $result instanceof AddressChangeResult ? $result : null, 'configurationError' => $configurationError, 'reportFailed' => $reportFailed]);
    }

    public function export(DateRangeReportRequest $request, RunAddressChangeReport $report, CsvExporter $csv, QueuedReportRunner $reports): StreamedResponse|RedirectResponse
    {
        $store = $this->resolveStore($request);
        if ($store->missingShopifyCredentials()) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        try {
            $result = $reports->completedResult($store, 'address_changes', $report::class, [$start, $end]) ?? $report->handle($store, $start, $end);
        } catch (Throwable $exception) {
            $this->logFailure('Address change CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['changed_at'], $row['gap_mins'], $row['email'], $row['addr_name'], $row['addr_line'], number_format((float) $row['total'], 2, '.', ''), $row['financial'], $row['fulfillment']], $result->rows);

        return $csv->download("address-changes-{$start}-to-{$end}.csv", ['Order', 'Placed', 'Changed', 'Time gap (minutes)', 'Email', 'Address name', 'Current shipping address', 'Total', 'Financial status', 'Fulfillment status'], $rows);
    }
}
