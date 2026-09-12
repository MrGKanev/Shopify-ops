<?php

namespace App\Http\Controllers;

use App\Application\Orders\SaveOrderNote;
use App\Http\Requests\SaveOrderNoteRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderNoteController extends Controller
{
    public function update(SaveOrderNoteRequest $request, SaveOrderNote $saveOrderNote): RedirectResponse
    {
        $orderNumber = (string) $request->validated('order_number');

        try {
            $saveOrderNote->handle($this->store($request), (string) $request->validated('order_id'), (string) $request->validated('note'));

            return back()->with('status', "Note saved for order #{$orderNumber}.");
        } catch (Throwable $exception) {
            Log::warning('Save order note failed.', ['exception_type' => $exception::class]);

            return back()->withErrors(['note' => $exception->getMessage()]);
        }
    }

    private function store(Request $request): Store
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');

        return $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
    }
}
