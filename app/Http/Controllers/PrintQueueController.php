<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrintQueueRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrintQueueController extends Controller
{
    public function index(Request $request): View
    {
        return view('print-queue.index', ['items' => $this->resolveStore($request)->printQueueItems()->oldest()->get()]);
    }

    public function store(PrintQueueRequest $request): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $orderNumber = (string) $request->validated('order_number');
        $store->printQueueItems()->firstOrCreate(['order_number' => $orderNumber], ['note' => (string) ($request->validated('note') ?? '')]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)
            ->withProperties(['order_number' => $orderNumber])->log('pq_add');

        return back()->with('status', 'Order added to the print queue.');
    }

    public function destroy(Request $request, int $item): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $printQueueItem = $store->printQueueItems()->findOrFail($item);
        $orderNumber = $printQueueItem->order_number;
        $printQueueItem->delete();
        activity('operator-actions')->causedBy($request->user())->performedOn($store)
            ->withProperties(['order_number' => $orderNumber])->log('pq_remove');

        return back()->with('status', 'Order removed from the print queue.');
    }

    public function clear(Request $request): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $count = $store->printQueueItems()->count();
        $store->printQueueItems()->delete();
        activity('operator-actions')->causedBy($request->user())->performedOn($store)
            ->withProperties(['count' => $count])->log('pq_clear');

        return back()->with('status', 'Print queue cleared.');
    }
}
