@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="Issue triage" :subtitle="$openCount.' open or in-progress issues for '.$activeStore->label">
            <x-button size="sm" :href="route('reports.run-audit')">Run audit</x-button>
        </x-page-header>

        <div class="flex flex-wrap gap-2">
            @foreach (['' => 'All', 'open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'ignored' => 'Ignored'] as $value => $label)
                <a class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $status === $value ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' }}" href="{{ route('operational-issues.index', $value === '' ? [] : ['status' => $value]) }}">{{ $label }}</a>
            @endforeach
        </div>

        <x-data-table :headers="['Issue', 'Priority', 'Owner', 'Seen', 'Status', 'Action']">
            @forelse ($issues as $issue)
                <tr id="issue-{{ $issue->id }}">
                    <td class="px-4 py-3"><div class="font-semibold">{{ $issue->title }}</div><div class="text-xs text-slate-500 dark:text-slate-400">{{ $issue->source_tool }} · {{ $issue->occurrences }} occurrences</div></td>
                    <td class="px-4 py-3"><x-badge :tone="in_array($issue->priority, ['high', 'urgent'], true) ? 'danger' : ($issue->priority === 'normal' ? 'warn' : 'default')">{{ $issue->priority }}</x-badge></td>
                    <td class="px-4 py-3">{{ $issue->owner?->name ?? 'Unassigned' }}</td>
                    <td class="px-4 py-3">{{ $issue->last_seen_at->diffForHumans() }}</td>
                    <td class="px-4 py-3">{{ str_replace('_', ' ', $issue->status) }}</td>
                    <td class="px-4 py-3">
                        <form class="flex flex-wrap gap-2" method="POST" action="{{ route('operational-issues.update', $issue) }}">
                            @csrf @method('PUT')
                            <select class="rounded border border-slate-300 bg-white px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-900" name="status">
                                @foreach (['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'ignored' => 'Ignored'] as $value => $label)<option value="{{ $value }}" @selected($issue->status === $value)>{{ $label }}</option>@endforeach
                            </select>
                            <select class="rounded border border-slate-300 bg-white px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-900" name="priority">
                                @foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $value => $label)<option value="{{ $value }}" @selected($issue->priority === $value)>{{ $label }}</option>@endforeach
                            </select>
                            <select class="rounded border border-slate-300 bg-white px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-900" name="owner_user_id"><option value="">Unassigned</option>@foreach ($owners as $owner)<option value="{{ $owner->id }}" @selected($issue->owner_user_id === $owner->id)>{{ $owner->name }}</option>@endforeach</select>
                            <x-button size="sm" type="submit">Save</x-button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="6">No issues match this filter.</td></tr>
            @endforelse
        </x-data-table>

        {{ $issues->links() }}
    </div>
@endsection
