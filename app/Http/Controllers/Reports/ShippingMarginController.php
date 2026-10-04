<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\RunShippingMarginReport;
use App\Application\Reports\ShippingMarginResult;
use App\Http\Controllers\Concerns\LogsReportFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShippingMarginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ShippingMarginController extends Controller
{
    use LogsReportFailure;

    public function create(): View
    {
        return view('reports.shipping-margin', $this->viewData());
    }

    public function store(ShippingMarginRequest $request, RunShippingMarginReport $report, QueuedReportRunner $reports): View|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $threshold = (float) $request->validated('threshold');
        $configurationError = $store->missingShopifyCredentials() || $store->missingShipStationCredentials();
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            $run = $reports->run($request, $store, 'shipping_margin', $report::class, [$startDate, $endDate, $threshold], $startDate, $endDate, 'scanned', 'count:rows');
            if ($reports->shouldRedirect($request, $run)) {
                return $reports->redirectToResult($request);
            }
            $result = $run->result();
            $reportFailed = $run->hasFailed();
        }

        return view('reports.shipping-margin', $this->viewData($startDate, $endDate, $threshold, $result, $reportFailed, $configurationError));
    }

    public function export(ShippingMarginRequest $request, RunShippingMarginReport $report, CsvExporter $csv, QueuedReportRunner $reports): StreamedResponse|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        try {
            $result = $reports->completedResult($store, 'shipping_margin', $report::class, [$startDate, $endDate, (float) $request->validated('threshold')]) ?? $report->handle($store, $startDate, $endDate, (float) $request->validated('threshold'));
        } catch (Throwable $exception) {
            $this->logFailure('Shipping margin CSV export failed.', $exception, $store);

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
