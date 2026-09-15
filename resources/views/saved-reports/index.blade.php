@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="Saved Reports" subtitle="Daily snapshots from completed audits." />

        <x-data-table :headers="['Saved', 'Tool', 'Period', 'Rows', '']">
            @forelse ($reports as $report)
                <tr>
                    <td class="px-4 py-3">{{ $report->updated_at->toDateTimeString() }}</td>
                    <td class="px-4 py-3">{{ $report->tool }}</td>
                    <td class="px-4 py-3">{{ $report->start_date->toDateString() }} → {{ $report->end_date->toDateString() }}</td>
                    <td class="px-4 py-3">{{ $report->rows_found }}</td>
                    <td class="px-4 py-3"><a class="font-medium text-indigo-600 dark:text-indigo-400" href="{{ route('saved-reports.show', $report) }}">Open</a></td>
                </tr>
            @empty
                <tr>
                    <td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="5">No saved reports yet.</td>
                </tr>
            @endforelse
        </x-data-table>

        {{ $reports->links() }}
    </div>
@endsection
