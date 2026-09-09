<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\FulfilledItemsResult;
use App\Application\Reports\RunFulfilledItemsReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\FulfilledItemsRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FulfilledItemsController extends Controller
{
    public function create(): View
    {
        return view('reports.fulfilled-items', $this->viewData());
    }

    public function store(FulfilledItemsRequest $request, RunFulfilledItemsReport $report): View
    {
        [$store, $startDate, $endDate] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Fulfilled items report failed.', $exception, $store);
            }
        }

        return view('reports.fulfilled-items', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError));
    }

    public function export(FulfilledItemsRequest $request, RunFulfilledItemsReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }
        try {
            $result = $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $this->logFailure('Fulfilled items CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }

        return $csv->download("fulfilled-items-{$startDate}-to-{$endDate}.csv", ['Product', 'Quantity'], array_map(fn (array $row): array => [$row['product'], $row['quantity']], $result->rows));
    }

    /** @return array{Store, string, string} */
    private function context(FulfilledItemsRequest $request): array
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();

        return [$store, (string) $request->validated('start_date'), (string) $request->validated('end_date')];
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
    private function viewData(?string $startDate = null, ?string $endDate = null, ?FulfilledItemsResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
