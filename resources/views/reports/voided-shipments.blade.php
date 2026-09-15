@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Fulfillment report" title="Voided Shipments" subtitle="ShipStation shipments voided in the selected period." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.voided-shipments.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end"><x-button type="submit">Run report</x-button></div>
        </form>

        @error('export')
            <x-alert tone="error">{{ $message }}</x-alert>
        @enderror
        @if ($configurationError)
            <x-alert tone="warn">ShipStation credentials are incomplete for the active store.</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">The report could not be completed. Check ShipStation and try again.</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-2xl font-bold">{{ count($result->rows) }} voided shipments</h2>
                    <form method="POST" action="{{ route('reports.voided-shipments.export') }}">
                        @csrf
                        <input type="hidden" name="start_date" value="{{ $result->startDate }}">
                        <input type="hidden" name="end_date" value="{{ $result->endDate }}">
                        <x-button type="submit" variant="ghost">Download CSV</x-button>
                    </form>
                </div>

                <x-data-table :headers="['Order', 'Void date', 'Ship date', 'Carrier / service', 'Tracking', 'Ship to']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $row['order_number'] }}</td>
                            <td class="px-4 py-3">{{ $row['void_date'] }}</td>
                            <td class="px-4 py-3">{{ $row['ship_date'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ strtoupper($row['carrier']) ?: '—' }}{{ $row['service'] ? ' / '.$row['service'] : '' }}</td>
                            <td class="px-4 py-3">{{ $row['tracking'] ?: '—' }}</td>
                            <td class="px-4 py-3"><strong>{{ $row['ship_to_name'] }}</strong><br>{{ implode(', ', array_filter([$row['ship_to_city'], $row['ship_to_state'], $row['ship_to_zip'], $row['ship_to_country']])) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">No voided shipments found.</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
