<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\ActiveShipStationConflictResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunActiveShipStationConflictReport;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActiveShipStationConflictRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ActiveShipStationConflictController extends Controller
{
    use RecordsReportRun;

    public function create(): View
    {
        return view('reports.active-shipstation-conflicts', $this->viewData());
    }

    public function store(ActiveShipStationConflictRequest $request, RunActiveShipStationConflictReport $report, RecordRun $runs): View
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
                $this->logFailure('Active ShipStation conflicts report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'active_shipstation_conflicts', $started, $start, $end, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.active-shipstation-conflicts', $this->viewData($start, $end, $result, $reportFailed, $configurationError));
    }

    public function export(ActiveShipStationConflictRequest $request, RunActiveShipStationConflictReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store,$start,$end] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify and ShipStation credentials are required.']);
        }try {
            $result = $report->handle($store, $start, $end);
        } catch (Throwable $exception) {
            $this->logFailure('Active ShipStation conflicts CSV failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }$rows = array_map(fn (array $row): array => [$row['order_number'], $row['issue'], $row['created_at'], $row['email'], $row['total'], $row['ss_status'], $row['ss_date'], $row['ss_total']], $result->rows);

        return $csv->download("active-shipstation-conflicts-{$start}-to-{$end}.csv", ['Order', 'Issue', 'Shopify date', 'Email', 'Shopify total', 'ShipStation status', 'ShipStation date', 'ShipStation total'], $rows);
    }

    /** @return array{Store,string,string} */
    private function context(ActiveShipStationConflictRequest $request): array
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
    private function viewData(?string $start = null, ?string $end = null, ?ActiveShipStationConflictResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $start ?? now()->subDays(30)->toDateString(), 'endDate' => $end ?? now()->toDateString()];
    }
}
