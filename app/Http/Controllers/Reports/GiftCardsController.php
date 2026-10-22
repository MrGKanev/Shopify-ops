<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunGiftCardsReport;
use App\Http\Requests\GiftCardsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GiftCardsController extends ReportController
{
    public function create(): View
    {
        return view('reports.gift-cards', ['days' => 30, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(GiftCardsRequest $request, RunGiftCardsReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $days = (int) $request->validated('days');
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'gift_cards', $report::class, [$days, now()->getTimestamp()], null, null, fn (?ReportResult $result, bool $failed) => view('reports.gift-cards', ['days' => $days, 'result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]));
    }
}
