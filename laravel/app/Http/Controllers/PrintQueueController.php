<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrintQueueRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrintQueueController extends Controller
{
    public function index(Request $request): View
    {
        return view('print-queue.index', ['items' => $this->activeStore($request)->printQueueItems()->oldest()->get()]);
    }

    public function store(PrintQueueRequest $request): RedirectResponse
    {
        $store = $this->activeStore($request);
        $store->printQueueItems()->firstOrCreate(['order_number' => $request->validated('order_number')], ['note' => (string) ($request->validated('note') ?? '')]);

        return back()->with('status', 'Order added to the print queue.');
    }

    public function destroy(Request $request, int $item): RedirectResponse
    {
        $this->activeStore($request)->printQueueItems()->findOrFail($item)->delete();

        return back()->with('status', 'Order removed from the print queue.');
    }

    public function clear(Request $request): RedirectResponse
    {
        $this->activeStore($request)->printQueueItems()->delete();

        return back()->with('status', 'Print queue cleared.');
    }

    private function activeStore(Request $request): Store
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');

        return $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
    }
}
