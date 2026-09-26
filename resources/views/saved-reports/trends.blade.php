@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="Audit Trends" subtitle="Daily missing-order totals from saved Run Audit snapshots." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="GET">
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end">
                <x-button type="submit">Apply</x-button>
            </div>
        </form>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-tile label="Avg missing / report" :value="$avgMissing" />
            <x-stat-tile label="Clear reports" :value="$clearReportCount" />
            <x-stat-tile label="Worst report" :value="$worstReport?->rows_found ?? '—'">
                @if ($worstReport)
                    <x-slot:sub><a class="text-indigo-600 dark:text-indigo-400" href="{{ route('saved-reports.show', $worstReport) }}">{{ $worstReport->report_date->toDateString() }}</a></x-slot:sub>
                @endif
            </x-stat-tile>
            <x-stat-tile label="Unique missing orders" :value="$uniqueMissingCount" />
        </div>

        <x-data-table :headers="['Date', 'Missing', 'Change', 'Trend']">
            @forelse ($rows as $row)
                <tr>
                    <td class="px-4 py-3"><a class="text-indigo-600 dark:text-indigo-400" href="{{ route('saved-reports.show', $row['id']) }}">{{ $row['date'] }}</a></td>
                    <td class="px-4 py-3">{{ $row['missing'] }}</td>
                    <td class="px-4 py-3">@if ($row['delta'] === null) — @elseif ($row['delta'] > 0) +{{ $row['delta'] }} @else {{ $row['delta'] }} @endif</td>
                    <td class="w-1/2 px-4 py-3"><div class="h-3 rounded bg-indigo-500" style="width: {{ $maximum > 0 ? round($row['missing'] / $maximum * 100) : 0 }}%"></div></td>
                </tr>
            @empty
                <tr>
                    <td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="4">{{ __('No saved audit data for this period.') }}</td>
                </tr>
            @endforelse
        </x-data-table>

        @if ($repeatOffenders !== [])
            <section class="flex flex-col gap-3">
                <h2 class="text-xl font-bold">{{ __('Repeat offenders') }}</h2>
                @include('partials.bulk-ignore-form')
                <x-data-table :headers="['Select', 'Order', 'Times missing', '']">
                    @foreach ($repeatOffenders as $offender)
                        <tr>
                            <td class="px-4 py-3"><input type="checkbox" name="order_numbers[]" value="{{ $offender['number'] }}" form="bulk-ignore" aria-label="{{ __('Select order :number', ['number' => $offender['number']]) }}"></td>
                            <td class="px-4 py-3">{{ $offender['number'] }}</td>
                            <td class="px-4 py-3">{{ $offender['count'] }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('ignored-orders.store') }}">
                                    @csrf
                                    <input type="hidden" name="order_number" value="{{ $offender['number'] }}">
                                    <input type="hidden" name="reason" value="Repeat offender">
                                    <x-button type="submit" size="sm" variant="danger">{{ __('Ignore') }}</x-button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
