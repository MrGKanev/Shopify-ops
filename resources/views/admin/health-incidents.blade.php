@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Health Incident History" subtitle="Outages, degraded checks, recoveries, and time to resolution.">
            <x-button size="sm" variant="ghost" :href="route('admin.health')">Live health</x-button>
        </x-page-header>

        <div class="grid gap-4 sm:grid-cols-3">
            <x-card><p class="text-sm text-slate-500 dark:text-slate-400">Open incidents</p><p class="mt-2 text-3xl font-bold tabular-nums">{{ $openCount }}</p></x-card>
            <x-card><p class="text-sm text-slate-500 dark:text-slate-400">Recovered in 30 days</p><p class="mt-2 text-3xl font-bold tabular-nums">{{ $resolvedLast30Days }}</p></x-card>
            <x-card><p class="text-sm text-slate-500 dark:text-slate-400">Average recovery</p><p class="mt-2 text-3xl font-bold tabular-nums">{{ $averageRecoverySeconds === null ? '—' : Carbon\CarbonInterval::seconds($averageRecoverySeconds)->cascade()->forHumans(['short' => true, 'parts' => 2]) }}</p></x-card>
        </div>

        <x-card>
            <form class="grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end" method="GET">
                <div><label for="incident-status">Status</label><select class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-900" id="incident-status" name="status"><option value="">All statuses</option><option value="open" @selected($status === 'open')>Open</option><option value="resolved" @selected($status === 'resolved')>Resolved</option></select></div>
                <div><label for="incident-component">Component</label><select class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-900" id="incident-component" name="component"><option value="">All components</option>@foreach ($components as $name => $label)<option value="{{ $name }}" @selected($selectedComponent === $name)>{{ $label }}</option>@endforeach</select></div>
                <x-button type="submit">Filter</x-button>
            </form>
        </x-card>

        <x-data-table :headers="['Component', 'Severity', 'Started', 'Duration', 'Observations', 'Status']">
            @forelse ($incidents as $incident)
                <tr>
                    <td class="px-4 py-3"><div class="font-semibold">{{ $incident->check_label }}</div><div class="mt-1 max-w-xl text-xs text-slate-500 dark:text-slate-400">{{ $incident->summary ?: 'No diagnostic message was provided.' }}</div></td>
                    <td class="px-4 py-3"><x-badge :tone="in_array($incident->severity, ['failed', 'crashed'], true) ? 'danger' : 'warn'">{{ $incident->severity }}</x-badge></td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $incident->started_at->format('Y-m-d H:i:s') }}</td>
                    <td class="px-4 py-3 whitespace-nowrap tabular-nums">{{ $incident->durationForHumans() }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $incident->observations }}</td>
                    <td class="px-4 py-3"><x-badge :tone="$incident->resolved_at ? 'ok' : 'danger'">{{ $incident->resolved_at ? 'Recovered' : 'Open' }}</x-badge>@if ($incident->resolved_at)<div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $incident->resolved_at->format('Y-m-d H:i:s') }}</div>@endif</td>
                </tr>
            @empty
                <tr><td class="px-4 py-10 text-center text-slate-500 dark:text-slate-400" colspan="6">No health incidents match these filters.</td></tr>
            @endforelse
        </x-data-table>

        {{ $incidents->links() }}
    </div>
@endsection
