@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="Job Queue" subtitle="Monitor background work, audit executions, and failed jobs for the active store.">
            @if ($usingRedis && $canViewHorizon)
                <x-button size="sm" variant="ghost" :href="route('horizon.index')">Open Horizon</x-button>
            @endif
        </x-page-header>

        <x-card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-xl font-bold">Audit executions</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Queued and completed core audits for this store.</p></div>
                <x-badge tone="info">{{ $auditJobs->count() }} recent</x-badge>
            </div>
            <x-data-table class="mt-4" :headers="['Range', 'Status', 'Queued', 'Finished', 'Result']">
                @forelse ($auditJobs as $auditJob)
                    <tr>
                        <td class="px-4 py-3 tabular-nums">{{ $auditJob->start_date->toDateString() }} → {{ $auditJob->end_date->toDateString() }}</td>
                        <td class="px-4 py-3"><x-badge :tone="$auditJob->status === 'completed' ? 'ok' : ($auditJob->status === 'failed' ? 'danger' : 'warn')">{{ ucfirst($auditJob->status) }}</x-badge></td>
                        <td class="px-4 py-3 tabular-nums">{{ $auditJob->created_at->toDateTimeString() }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $auditJob->finished_at?->toDateTimeString() ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($auditJob->status === 'completed')
                                {{ $auditJob->rows_found ?? '—' }} missing
                            @elseif ($auditJob->status === 'failed')
                                {{ $auditJob->error_category ?? '—' }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="5">No audit executions yet.</td></tr>
                @endforelse
            </x-data-table>
        </x-card>

        @if ($usingRedis)
            <x-card>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div><h2 class="text-xl font-bold">Pending jobs</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Redis queues are monitored in Horizon.</p></div>
                    @if ($canViewHorizon)
                        <x-button size="sm" :href="route('horizon.index')">View pending jobs</x-button>
                    @else
                        <div class="text-right"><x-badge tone="info">Admins only</x-badge><p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Pending job details are only visible to administrators in the Horizon dashboard.</p></div>
                    @endif
                </div>
            </x-card>
        @else
            <x-card>
                <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-xl font-bold">Pending jobs</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Waiting in the {{ config('queue.default') }} queue.</p></div><x-badge tone="info">Pending · {{ $jobs->total() }}</x-badge></div>
                <x-data-table class="mt-4" :headers="['ID', 'Queue', 'Job', 'Attempts', 'Available']">
                    @forelse ($jobs as $job)
                        @php($payload = json_decode($job->payload, true) ?: [])
                        <tr><td class="px-4 py-3 tabular-nums">{{ $job->id }}</td><td class="px-4 py-3">{{ $job->queue }}</td><td class="max-w-md truncate px-4 py-3">{{ $payload['displayName'] ?? $payload['job'] ?? 'Unknown job' }}</td><td class="px-4 py-3 tabular-nums">{{ $job->attempts }}</td><td class="px-4 py-3 tabular-nums">{{ date('Y-m-d H:i:s', $job->available_at) }}</td></tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="5">No pending jobs.</td></tr>
                    @endforelse
                </x-data-table>
                {{ $jobs->links() }}
            </x-card>
        @endif

        <x-card>
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-xl font-bold">Failed jobs</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Retry a recoverable job or remove obsolete failures.</p></div><x-badge :tone="$failed->total() > 0 ? 'danger' : 'ok'">Failed · {{ $failed->total() }}</x-badge></div>
            <x-data-table class="mt-4" :headers="['Job', 'Queue', 'Failed', 'Actions']">
                @forelse ($failed as $job)
                    @php($payload = json_decode($job->payload, true) ?: [])
                    <tr><td class="max-w-md truncate px-4 py-3">{{ $payload['displayName'] ?? $payload['job'] ?? 'Unknown job' }}<div class="mt-1 font-mono text-xs text-slate-500 dark:text-slate-400">{{ $job->uuid }}</div></td><td class="px-4 py-3">{{ $job->queue }}</td><td class="px-4 py-3 tabular-nums">{{ $job->failed_at }}</td><td class="px-4 py-3"><div class="flex gap-2"><form method="POST" action="{{ route('jobs.retry', $job->uuid) }}">@csrf <x-button size="sm" type="submit">Retry</x-button></form><form method="POST" action="{{ route('jobs.destroy', $job->uuid) }}">@csrf @method('DELETE') <x-button size="sm" variant="danger" type="submit">Forget</x-button></form></div></td></tr>
                @empty
                    <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="4">No failed jobs.</td></tr>
                @endforelse
            </x-data-table>
            {{ $failed->links() }}
        </x-card>
    </div>
@endsection
