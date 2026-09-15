<?php

namespace App\Http\Controllers;

use App\Domain\Orders\OrderTypeClassifier;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OrderTypeClassifier $classifier): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $reports = $store->auditSnapshots()->where('tool', 'run_audit')->latest('report_date')->limit(2)->get();
        $latest = $reports->first();
        $recent = $store->auditSnapshots()->where('tool', 'run_audit')->latest('report_date')->limit(30)->get()->reverse()->values();
        $latestMissing = is_array($latest?->result['missing'] ?? null) ? $latest->result['missing'] : [];
        $latestNumbers = array_map(fn (array $order): string => (string) ($order['name'] ?? $order['order_number'] ?? ''), $latestMissing);

        $missingByType = [];
        foreach ($latestMissing as $order) {
            $type = $classifier->classify($order);
            $missingByType[$type] = ($missingByType[$type] ?? 0) + 1;
        }
        arsort($missingByType);

        $createdDates = array_filter(array_map(fn (array $order): ?string => is_string($order['created_at'] ?? null) ? substr($order['created_at'], 0, 10) : null, $latestMissing));
        $oldestMissingAge = $createdDates === [] ? null : abs(today()->diffInDays(min($createdDates)));

        $firstSeen = [];
        $lastSeen = [];
        $appearances = [];
        foreach ($recent as $snapshot) {
            foreach ((array) ($snapshot->result['missing'] ?? []) as $order) {
                $number = (string) ($order['name'] ?? $order['order_number'] ?? '');
                if ($number === '') {
                    continue;
                }
                $firstSeen[$number] ??= $snapshot->report_date;
                $lastSeen[$number] = $snapshot->report_date;
                $appearances[$number] = ($appearances[$number] ?? 0) + 1;
            }
        }
        $resolutionDays = [];
        foreach ($firstSeen as $number => $first) {
            if (! in_array($number, $latestNumbers, true)) {
                $resolutionDays[] = $first->diffInDays($lastSeen[$number]);
            }
        }
        $avgResolutionDays = $resolutionDays === [] ? null : round(array_sum($resolutionDays) / count($resolutionDays), 1);

        $cadenceGaps = [];
        for ($i = 1; $i < $recent->count(); $i++) {
            $cadenceGaps[] = $recent[$i - 1]->report_date->diffInDays($recent[$i]->report_date);
        }
        $auditCadenceDays = $cadenceGaps === [] ? null : round(array_sum($cadenceGaps) / count($cadenceGaps), 1);
        $clearAuditRate = $recent->isEmpty() ? null : round($recent->filter(fn ($snapshot): bool => (int) $snapshot->rows_found === 0)->count() / $recent->count() * 100);
        $recurringMissingCount = count(array_filter(array_unique($latestNumbers), fn (string $number): bool => ($appearances[$number] ?? 0) >= 2));

        return view('dashboard', [
            'latest' => $latest,
            'previousMissing' => $reports->get(1)?->rows_found,
            'totalReports' => $store->auditSnapshots()->where('tool', 'run_audit')->count(),
            'totalMissing' => (int) $store->auditSnapshots()->where('tool', 'run_audit')->sum('rows_found'),
            'ignoredCount' => $store->ignoredOrders()->count(),
            'staleIgnoredCount' => $store->ignoredOrders()->where('ignored_at', '<=', today()->subDays(30))->count(),
            'pushesToday' => $store->pushLogs()->where('pushed_at', '>=', today())->count(),
            'pushesMonth' => $store->pushLogs()->where('pushed_at', '>=', now()->subDays(30))->count(),
            'missingOrders' => array_slice($latestMissing, 0, 10),
            'missingByType' => $missingByType,
            'oldestMissingAge' => $oldestMissingAge,
            'avgResolutionDays' => $avgResolutionDays,
            'auditCadenceDays' => $auditCadenceDays,
            'auditsLast30Days' => $store->auditSnapshots()->where('tool', 'run_audit')->where('report_date', '>=', today()->subDays(29))->count(),
            'clearAuditRate' => $clearAuditRate,
            'recurringMissingCount' => $recurringMissingCount,
            'sevenDayChart' => $recent->slice(-7)->map(fn ($s) => ['date' => $s->report_date->toDateString(), 'missing' => $s->rows_found])->values(),
        ]);
    }
}
