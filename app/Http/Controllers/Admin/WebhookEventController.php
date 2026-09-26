<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessShopifyWebhookEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebhookEventController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'topic' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:received,processed,failed,ignored'],
        ]);
        $store = $this->resolveStore($request);
        $topic = trim((string) ($validated['topic'] ?? ''));
        $status = (string) ($validated['status'] ?? '');
        $events = $store->webhookEvents()
            ->when($topic !== '', fn ($query) => $query->where('topic', 'like', "%{$topic}%"))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.webhook-events', compact('events', 'topic', 'status'));
    }

    public function retry(Request $request, int $event): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $webhookEvent = $store->webhookEvents()->findOrFail($event);
        if ($webhookEvent->status !== 'failed') {
            return back()->withErrors(['webhook_event' => 'Only failed webhook events can be retried.']);
        }

        $updated = $store->webhookEvents()
            ->whereKey($webhookEvent->getKey())
            ->where('status', 'failed')
            ->update(['status' => 'received', 'processed_at' => null, 'error_category' => null]);
        if ($updated !== 1) {
            return back()->withErrors(['webhook_event' => 'Only failed webhook events can be retried.']);
        }

        ProcessShopifyWebhookEvent::dispatch((int) $webhookEvent->getKey());

        return back()->with('status', 'Webhook retry queued.');
    }
}
