@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Webhook Events" subtitle="Verified Shopify events received for the active store." />

        <x-card>
            <form class="flex flex-wrap items-end gap-3" method="GET">
                <div class="flex min-w-64 flex-1 flex-col gap-2"><label class="text-sm font-medium" for="topic">Topic</label><input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="topic" name="topic" value="{{ $topic }}" placeholder="orders/updated"></div>
                <div class="flex flex-col gap-2"><label class="text-sm font-medium" for="status">Status</label><select class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="status" name="status"><option value="">All statuses</option>@foreach (['received', 'processed', 'failed', 'ignored'] as $value)<option value="{{ $value }}" @selected($status === $value)>{{ ucfirst($value) }}</option>@endforeach</select></div>
                <x-button type="submit">Filter</x-button>
            </form>
        </x-card>

        <x-data-table :headers="['Received', 'Topic', 'Resource', 'API', 'Status']">
            @forelse ($events as $event)
                <tr><td class="px-4 py-3 tabular-nums">{{ $event->occurred_at->toDateTimeString() }}</td><td class="px-4 py-3 font-semibold">{{ $event->topic }}</td><td class="px-4 py-3 font-mono text-xs">{{ $event->subject_id ?? '—' }}</td><td class="px-4 py-3">{{ $event->api_version ?? '—' }}</td><td class="px-4 py-3"><x-badge :tone="$event->status === 'failed' ? 'danger' : ($event->status === 'processed' ? 'ok' : 'info')">{{ ucfirst($event->status) }}</x-badge></td></tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="5">No verified webhook events received yet.</td></tr>
            @endforelse
        </x-data-table>
        {{ $events->links() }}
    </div>
@endsection
