<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\OrphanOrderResult;
use App\Application\Reports\RunOrphanOrderReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrphanOrderRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OrphanOrderController extends Controller
{
    public function create(): View
    {
        return view('reports.orphan-orders', $this->viewData());
    }

    public function store(OrphanOrderRequest $request, RunOrphanOrderReport $report): View
    {
        [$store,$start,$end] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $report->handle($store, $start, $end);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Orphan order report failed.', $exception, $store);
            }
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
    {/** @var Store $activeStore */ $activeStore = $request->attributes->get('activeStore');

        return [$request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail(), (string) $request->validated('start_date'), (string) $request->validated('end_date')];
    }

    private function configurationError(Store $store): bool
    {
        return trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '' || trim((string) $store->shipstation_api_key) === '' || trim((string) $store->shipstation_api_secret) === '';
    }

    private function logFailure(string $message, Throwable $exception, Store $store): void
    {
        Log::warning($message, ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
    }

    /** @return array<string,mixed> */
    private function viewData(?string $start = null, ?string $end = null, ?OrphanOrderResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $start ?? now()->subDays(30)->toDateString(), 'endDate' => $end ?? now()->toDateString()];
    }
}
