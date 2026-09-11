<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\NoTrackingResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunNoTrackingReport;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\NoTrackingRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class NoTrackingController extends Controller
{
    use RecordsReportRun;

    public function create(): View
    {
        return view('reports.no-tracking', $this->viewData());
    }

    public function store(NoTrackingRequest $request, RunNoTrackingReport $report, RecordRun $runs): View
    {
        [$store, $start, $end, $threshold] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $start, $end, $threshold);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('No-tracking report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'no_tracking', $started, $start, $end, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.no-tracking', $this->viewData($start, $end, $threshold, $result, $reportFailed, $configurationError));
    }

    public function export(NoTrackingRequest $request, RunNoTrackingReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store, $start, $end, $threshold] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }
        try {
            $result = $report->handle($store, $start, $end, $threshold);
        } catch (Throwable $exception) {
            $this->logFailure('No-tracking CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $rows = [];
        foreach ($result->rows as $row) {
            foreach ($row['missing'] as $missing) {
                $rows[] = [$row['order_number'], $row['created_at'], $missing['created_at'], $missing['hours_ago'], $missing['company'], $missing['status'], $row['email'], $row['total']];
            }
        }

        return $csv->download("fulfilled-without-tracking-{$start}-to-{$end}.csv", ['Order', 'Placed', 'Fulfillment date', 'Hours since', 'Carrier', 'Status', 'Email', 'Total'], $rows);
    }

    /** @return array{Store, string, string, int} */
    private function context(NoTrackingRequest $request): array
    {
        /** @var Store $activeStore */ $activeStore = $request->attributes->get('activeStore');

        return [$request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail(), (string) $request->validated('start_date'), (string) $request->validated('end_date'), (int) $request->validated('threshold')];
    }

    private function configurationError(Store $store): bool
    {
        return trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
    }

    private function logFailure(string $message, Throwable $exception, Store $store): void
    {
        Log::warning($message, ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
    }

    /** @return array<string, mixed> */
    private function viewData(?string $start = null, ?string $end = null, int $threshold = 24, ?NoTrackingResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('threshold', 'result', 'reportFailed', 'configurationError') + ['startDate' => $start ?? now()->subDays(30)->toDateString(), 'endDate' => $end ?? now()->toDateString()];
    }
}
