<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:64', 'regex:/\d/']]);
        $query = trim((string) ($validated['q'] ?? ''));
        $number = preg_replace('/\D+/', '', $query) ?? '';
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $results = null;
        if ($number !== '') {
            // ponytail: bounded local-history scan; add indexed order rows if any table exceeds 500 entries per store.
            $snapshots = $store->auditSnapshots()->latest('report_date')->limit(500)->get();
            $reports = [];
            foreach ($snapshots as $snapshot) {
                foreach ($snapshot->result['missing'] ?? [] as $order) {
                    if (! is_array($order) || ! $this->matches($order['order_number'] ?? $order['name'] ?? '', $number)) {
                        continue;
                    }
                    $orderNumber = (string) ($order['order_number'] ?? $order['name'] ?? '');
                    $reports[] = ['id' => $snapshot->getKey(), 'order_number' => $orderNumber, 'report_date' => $snapshot->report_date->toDateString()];
                }
            }
            $results = [
                'reports' => $reports,
                'pushes' => $this->matching($store->pushLogs()->latest('pushed_at')->limit(500)->get(), $number),
                'ignored' => $this->matching($store->ignoredOrders()->latest('ignored_at')->limit(500)->get(), $number),
            ];
        }

        return view('global-search', compact('query', 'results'));
    }

    private function matching(Collection $rows, string $number): Collection
    {
        return $rows->filter(fn ($row): bool => $this->matches($row->order_number, $number))->values();
    }

    private function matches(mixed $value, string $number): bool
    {
        $candidate = preg_replace('/\D+/', '', is_scalar($value) ? (string) $value : '') ?? '';

        return $candidate !== '' && (str_contains($candidate, $number) || str_contains($number, $candidate));
    }
}
