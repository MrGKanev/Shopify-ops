<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunGiftCardsReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\GiftCardsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GiftCardsController extends Controller
{
    public function create(): View
    {
        return view('reports.gift-cards', ['days' => 30, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(GiftCardsRequest $request, RunGiftCardsReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $days = (int) $request->validated('days');
        $result = null;
        $reportFailed = false;
        $configurationError = $store->missingShopifyCredentials();

        if (! $configurationError) {
            $run = $reports->run($request, $store, 'gift_cards', $report::class, [$days, now()->getTimestamp()], null, null);
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.gift-cards', ['days' => $days, 'result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
