<?php

namespace App\Http\Controllers;

use App\Http\Requests\IgnoredOrderImportRequest;
use App\Http\Requests\IgnoredOrderRequest;
use App\Models\IgnoredOrder;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IgnoredOrderController extends Controller
{
    public function index(Request $request): View
    {
        return view('ignored-orders.index', ['orders' => $this->activeStore($request)->ignoredOrders()->latest('ignored_at')->latest('id')->get()]);
    }

    public function store(IgnoredOrderRequest $request): RedirectResponse
    {
        $number = $this->normalize((string) $request->validated('order_number'));
        $this->storeModel($request)->ignoredOrders()->updateOrCreate(['order_number' => $number], ['reason' => trim((string) ($request->validated('reason') ?? '')), 'ignored_at' => today()]);

        return back()->with('status', "Order #{$number} is ignored.");
    }

    public function destroy(Request $request, IgnoredOrder $ignoredOrder): RedirectResponse
    {
        abort_unless($ignoredOrder->store_id === $this->storeModel($request)->getKey(), 404);
        $ignoredOrder->delete();

        return back()->with('status', 'Order restored to audits.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']])['ids'];
        $count = $this->storeModel($request)->ignoredOrders()->whereKey($ids)->delete();

        return back()->with('status', "{$count} orders restored to audits.");
    }

    public function import(IgnoredOrderImportRequest $request): RedirectResponse
    {
        $file = $request->file('file')->openFile();
        $rows = [];
        $first = true;
        while (! $file->eof()) {
            $row = $file->fgetcsv();
            $raw = is_array($row) && is_scalar($row[0] ?? null) ? (string) $row[0] : '';
            $number = $this->normalize($raw);
            if ($first && ! ctype_digit($number)) {
                $first = false;

                continue;
            }
            $first = false;
            if ($number !== '') {
                $rows[$number] = ['reason' => trim((string) ($request->validated('reason') ?? '')) ?: 'CSV import '.today()->toDateString(), 'ignored_at' => today()];
            }
        }
        foreach ($rows as $number => $values) {
            $this->storeModel($request)->ignoredOrders()->updateOrCreate(['order_number' => $number], $values);
        }

        return back()->with('status', count($rows).' orders imported.');
    }

    private function storeModel(Request $request): Store
    { /** @var Store $store */ $store = $request->attributes->get('activeStore');

        return $request->user()->stores()->whereKey($store->getKey())->firstOrFail();
    }

    private function activeStore(Request $request): Store
    {
        return $this->storeModel($request);
    }

    private function normalize(string $number): string
    {
        return preg_replace('/\D+/', '', trim($number)) ?? '';
    }
}
