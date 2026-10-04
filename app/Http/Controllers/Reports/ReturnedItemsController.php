<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunReturnedItemsReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ReturnedItemsController extends Controller
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.returned-items', $this->viewData());
    }

    public function store(DateRangeReportRequest $request, RunReturnedItemsReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;

        if (! $configurationError) {
            $run = $reports->run($request, $store, 'returned_items', $report::class, [$startDate, $endDate], $startDate, $endDate, 'scanned', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.returned-items', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError));
    }

    public function export(DateRangeReportRequest $request, RunReturnedItemsReport $report, CsvExporter $csv, QueuedReportRunner $reports): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }

        try {
            $result = $reports->completedResult($store, 'returned_items', $report::class, [$startDate, $endDate]) ?? $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $this->logFailure('Returned items CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }

        $rows = array_map(fn (array $row): array => [$row['product'], $row['quantity']], $result->rows);

        return $csv->download("returned-items-{$startDate}-to-{$endDate}.csv", ['Product', 'Quantity'], $rows);
    }

    /** @return array{Store, string, string} */
    private function context(DateRangeReportRequest $request): array
    {
        $store = $this->resolveStore($request);

        return [$store, (string) $request->validated('start_date'), (string) $request->validated('end_date')];
    }

    private function configurationError(Store $store): bool
    {
        return $store->missingShopifyCredentials();
    }

    /** @return array<string, mixed> */
    private function viewData(?string $startDate = null, ?string $endDate = null, ?ScanResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
