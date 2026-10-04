<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunFulfilledItemsReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeReportRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FulfilledItemsController extends Controller
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.fulfilled-items', $this->viewData());
    }

    public function store(DateRangeReportRequest $request, RunFulfilledItemsReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'fulfilled_items', $report::class, [$startDate, $endDate], $startDate, $endDate, 'scanned', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.fulfilled-items', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError));
    }

    public function export(DateRangeReportRequest $request, RunFulfilledItemsReport $report, CsvExporter $csv, QueuedReportRunner $reports): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }
        try {
            $result = $reports->completedResult($store, 'fulfilled_items', $report::class, [$startDate, $endDate]) ?? $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $this->logFailure('Fulfilled items CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }

        return $csv->download("fulfilled-items-{$startDate}-to-{$endDate}.csv", ['Product', 'Quantity'], array_map(fn (array $row): array => [$row['product'], $row['quantity']], $result->rows));
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
