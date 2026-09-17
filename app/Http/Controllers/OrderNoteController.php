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
        $orderId = (string) $request->validated('order_id');
        $note = (string) $request->validated('note');

        try {
            $saveOrderNote->handle($this->store($request), $orderId, $note);
            activity('operator-actions')->causedBy($request->user())->performedOn($this->store($request))
                ->withProperties(['shopify_id' => $orderId, 'note_length' => strlen($note)])->log('save_order_note');

            return back()->with('status', "Note saved for order #{$orderNumber}.");
        } catch (Throwable $exception) {
            Log::warning('Save order note failed.', ['exception_type' => $exception::class]);

            return back()->withErrors(['note' => $exception->getMessage()]);
        }
    }

    private function store(Request $request): Store
    {

        return $this->resolveStore($request);
    }
}
