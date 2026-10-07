<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunOrderContributionReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderContributionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OrderContributionController extends Controller
{
    public function create(): View
    {
        return view('reports.order-contribution', $this->viewData());
    }

    public function store(OrderContributionRequest $request, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        $after = $request->validated('after');
        $configurationError = $store->missingShopifyCredentials();
        $run = null;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'order_contribution', RunOrderContributionReport::class, [$start, $end, $after], $start, $end);
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
        }

        return view('reports.order-contribution', $this->viewData($start, $end, $run?->result(), $run?->hasFailed() ?? false, $configurationError));
    }

    /** @param ReportResult<array<string, mixed>>|null $result
     * @return array<string, mixed> */
    private function viewData(?string $start = null, ?string $end = null, ?ReportResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return ['startDate' => $start ?? now()->subDays(30)->toDateString(), 'endDate' => $end ?? now()->toDateString(), 'result' => $result, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError];
    }
}
