<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
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
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
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
}
