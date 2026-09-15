<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RecordRun;
use App\Application\Reports\RepeatRefundResult;
use App\Application\Reports\RunRepeatRefundReport;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\RepeatRefundRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class RepeatRefundController extends Controller
{
    use RecordsReportRun;

    public function create(): View
    {
        return view('reports.repeat-refunds', ['startDate' => now()->subDays(90)->toDateString(), 'endDate' => now()->toDateString(), 'minimum' => 2, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(RepeatRefundRequest $request, RunRepeatRefundReport $report, RecordRun $runs): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        $minimum = (int) $request->validated('minimum');
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $start, $end, $minimum);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Repeat refund report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
            $this->recordReportRun($runs, $store, 'repeat_refunds', $started, $start, $end, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.repeat-refunds', ['startDate' => $start, 'endDate' => $end, 'minimum' => $minimum, 'result' => $result instanceof RepeatRefundResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
