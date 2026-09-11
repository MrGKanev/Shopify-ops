<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunShippingMarginReport;
use App\Application\Reports\ShippingMarginResult;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShippingMarginRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ShippingMarginController extends Controller
{
    use RecordsReportRun;

    public function create(): View
    {
        return view('reports.shipping-margin', $this->viewData());
    }

    public function store(ShippingMarginRequest $request, RunShippingMarginReport $report, RecordRun $runs): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $threshold = (float) $request->validated('threshold');
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '' || trim((string) $store->shipstation_api_key) === '' || trim((string) $store->shipstation_api_secret) === '';
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $startDate, $endDate, $threshold);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Shipping margin report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
            $this->recordReportRun($runs, $store, 'shipping_margin', $started, $startDate, $endDate, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.shipping-margin', $this->viewData($startDate, $endDate, $threshold, $result, $reportFailed, $configurationError));
    }

    public function export(ShippingMarginRequest $request, RunShippingMarginReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        try {
            $result = $report->handle($store, $startDate, $endDate, (float) $request->validated('threshold'));
        } catch (Throwable $exception) {
            Log::warning('Shipping margin CSV export failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }

        return $csv->download("shipping-margin-{$startDate}-to-{$endDate}.csv", ['Order', 'Ship date', 'Carrier', 'Service', 'Ship cost', 'Shipping charged', 'Loss', 'Email', 'Order total'], array_map(fn (array $row): array => [$row['order_number'], $row['ship_date'], $row['carrier'], $row['service'], $row['ship_cost'], $row['shipping_charged'], $row['loss'], $row['email'], $row['total']], $result->rows));
    }

    /** @return array<string, mixed> */
    private function viewData(?string $startDate = null, ?string $endDate = null, float $threshold = 15, ?ShippingMarginResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('threshold', 'result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
