@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Fulfillment report" title="Shipment Aging" subtitle="Live ShipStation awaiting-shipment orders older than the threshold." />

        <form class="flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.shipment-aging.store') }}">
            @csrf
            <div>
                <label class="text-sm font-medium" for="threshold">{{ __('Older than days') }}</label>
                <input class="mt-2 block rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="threshold" name="threshold" type="number" min="1" max="365" value="{{ old('threshold', $threshold) }}">
                @error('threshold')
                    <p class="text-sm text-red-600">{{ __($message) }}</p>
                @enderror
            </div>
            <x-button type="submit">{{ __('Run report') }}</x-button>
        </form>

        @error('export')
            <x-alert tone="error">{{ __($message) }}</x-alert>
        @enderror
        @if ($configurationError)
            <x-alert tone="warn">{{ __('ShipStation credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check ShipStation and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-2xl font-bold">{{ $result->scanned }} awaiting orders scanned · {{ count($result->rows) }} older than {{ $result->threshold }} days</h2>
                    <form method="POST" action="{{ route('reports.shipment-aging.export') }}">
                        @csrf
                        <input type="hidden" name="threshold" value="{{ $result->threshold }}">
                        <x-button type="submit" variant="ghost">{{ __('Download CSV') }}</x-button>
                    </form>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <x-card padding="p-4">
                        <h3 class="font-bold">{{ __('By SKU') }}</h3>
                        @foreach (array_slice($result->bySku, 0, 8) as $row)
                            <p>{{ $row['sku'] }} · {{ $row['orders'] }} orders · {{ $row['qty'] }} qty · oldest {{ $row['oldest_days'] }}d</p>
                        @endforeach
                    </x-card>
                    <x-card padding="p-4">
                        <h3 class="font-bold">{{ __('By type') }}</h3>
                        @foreach (array_slice($result->byType, 0, 8) as $row)
                            <p>{{ $row['type'] }} · {{ $row['orders'] }} orders · oldest {{ $row['oldest_days'] }}d</p>
                        @endforeach
                    </x-card>
                </div>

                <x-data-table :headers="['Order', 'Date', 'Days', 'Customer', 'Total', 'Type', 'SKUs']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $row['order_number'] }}</td>
                            <td class="px-4 py-3">{{ $row['order_date'] }}</td>
                            <td class="px-4 py-3">{{ $row['days'] }}d</td>
                            <td class="px-4 py-3">{{ $row['customer'] }}<br>{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['total'], 2) }}</td>
                            <td class="px-4 py-3">{{ $row['order_type'] }}</td>
                            <td class="px-4 py-3">
                                @foreach ($row['skus'] as $sku => $qty)
                                    <div>{{ $sku }} ×{{ $qty }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="7">{{ __('No aging shipments.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
