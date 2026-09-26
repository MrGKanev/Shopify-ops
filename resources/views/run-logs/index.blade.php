@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Run History" subtitle="Recent audit and scan executions for this store." />

        <x-card>
            <form class="flex flex-col gap-3 sm:flex-row sm:items-end" method="GET">
                <div class="min-w-0 flex-1">
                    <label class="text-sm font-medium" for="run-search">{{ __('Tool, status, or error') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="run-search" name="q" type="search" value="{{ $q }}">
                </div>
                <x-button type="submit">{{ __('Search') }}</x-button>
            </form>
        </x-card>

        <x-data-table :headers="['Time', 'Tool', 'Status', 'Period', 'Duration', 'Rows', 'Scanned', 'Error / Meta']">
            @forelse ($runs as $run)
                <tr>
                    <td class="px-4 py-3">{{ $run->created_at->toDateTimeString() }}</td>
                    <td class="px-4 py-3">{{ $run->tool }}</td>
                    <td class="px-4 py-3">{{ $run->status }}</td>
                    <td class="px-4 py-3">{{ $run->start_date?->toDateString() ?: '-' }} → {{ $run->end_date?->toDateString() ?: '-' }}</td>
                    <td class="px-4 py-3">{{ $run->duration_seconds !== null ? $run->duration_seconds.'s' : '-' }}</td>
                    <td class="px-4 py-3">{{ $run->rows_found ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $run->scanned ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $run->error ?: collect($run->meta)->map(fn ($value, $key) => $key.': '.(is_bool($value) ? ($value ? 'yes' : 'no') : $value))->implode(', ') ?: '-' }}</td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="8">{{ __('No runs logged yet.') }}</td></tr>
            @endforelse
        </x-data-table>

        {{ $runs->links() }}
    </div>
@endsection
