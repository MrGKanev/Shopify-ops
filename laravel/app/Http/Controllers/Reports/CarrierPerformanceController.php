<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\CarrierPerformanceResult;
use App\Application\Reports\RunCarrierPerformanceReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarrierPerformanceRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class CarrierPerformanceController extends Controller
{
    public function create(): View
    {
        return view('reports.carrier-performance', $this->viewData());
    }

    public function store(CarrierPerformanceRequest $request, RunCarrierPerformanceReport $report): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $configurationError = trim((string) $store->shipstation_api_key) === '' || trim((string) $store->shipstation_api_secret) === '';
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Carrier performance report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.carrier-performance', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError));
    }

    /** @return array<string, mixed> */
    private function viewData(?string $startDate = null, ?string $endDate = null, ?CarrierPerformanceResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
