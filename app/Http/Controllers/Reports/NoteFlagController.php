<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunNoteFlagReport;
use App\Http\Requests\NoteFlagRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class NoteFlagController extends ReportController
{
    private const DEFAULT_KEYWORDS = 'urgent, hold, cancel, wrong, error, stop, do not ship, dont ship, wait, attention';

    public function create(): View
    {
        return view('reports.note-flags', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'keywords' => self::DEFAULT_KEYWORDS, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(NoteFlagRequest $request, RunNoteFlagReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $keywordsRaw = (string) $request->validated('keywords');
        $keywords = array_values(array_filter(array_map(fn (string $keyword): string => mb_strtolower(trim($keyword)), explode(',', $keywordsRaw))));
        $configurationError = $store->missingShopifyCredentials();

        return $this->runQueued($reports, $request, $store, $configurationError, 'note_flags', $report::class, [$startDate, $endDate, $keywords], $startDate, $endDate, fn (?ReportResult $result, bool $reportFailed) => view('reports.note-flags', ['startDate' => $startDate, 'endDate' => $endDate, 'keywords' => $keywordsRaw, 'result' => $result instanceof ReportResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]));
    }
}
