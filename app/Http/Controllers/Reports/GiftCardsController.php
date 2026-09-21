<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RecordRun;
use App\Application\Reports\RunGiftCardsReport;
use App\Application\Reports\ScanResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\GiftCardsRequest;
use Illuminate\View\View;
use Throwable;

class GiftCardsController extends Controller
{
    use LogsReportFailure, RecordsReportRun;

    public function create(): View
    {
        return view('reports.gift-cards', ['days' => 30, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(GiftCardsRequest $request, RunGiftCardsReport $report, RecordRun $runs): View
    {
        $store = $this->resolveStore($request);
        $days = (int) $request->validated('days');
        $result = null;
        $reportFailed = false;
        $configurationError = $store->missingShopifyCredentials();

        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $days, now()->timestamp);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Gift card report failed.', $exception, $store);
            }
            $this->recordReportRun($runs, $store, 'gift_cards', $started, null, null, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.gift-cards', ['days' => $days, 'result' => $result instanceof ScanResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
