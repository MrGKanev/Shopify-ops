<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\GiftCardsResult;
use App\Application\Reports\RecordRun;
use App\Application\Reports\RunGiftCardsReport;
use App\Http\Controllers\Concerns\RecordsReportRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\GiftCardsRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class GiftCardsController extends Controller
{
    use RecordsReportRun;

    public function create(): View
    {
        return view('reports.gift-cards', ['days' => 30, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(GiftCardsRequest $request, RunGiftCardsReport $report, RecordRun $runs): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $days = (int) $request->validated('days');
        $result = null;
        $reportFailed = false;
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';

        if (! $configurationError) {
            $started = microtime(true);
            try {
                $result = $report->handle($store, $days, now()->timestamp);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Gift card report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
            $this->recordReportRun($runs, $store, 'gift_cards', $started, null, null, $result->scanned ?? 0, count($result->rows ?? []), $reportFailed);
        }

        return view('reports.gift-cards', ['days' => $days, 'result' => $result instanceof GiftCardsResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
