@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Risk report" title="Refunds Tracker" subtitle="Refunded Shopify orders cross-checked against ShipStation status." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.refund-tracker.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="text-sm text-red-600">{{ __($message) }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end">
                <x-button type="submit">{{ __('Run report') }}</x-button>
            </div>
        </form>

        @if ($shopifyConfigurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($shipStationConfigurationWarning)
            <x-alert tone="warn">{{ __('ShipStation credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check the integrations and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <section class="grid gap-3 sm:grid-cols-3">
                    <x-card padding="p-4">
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Refunded orders') }}</p>
                        <p class="text-2xl font-bold">{{ $result->scanned }}</p>
                    </x-card>
                    <x-card padding="p-4">
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Still active in ShipStation') }}</p>
                        <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ $result->active }}</p>
                    </x-card>
                    <x-card padding="p-4">
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Missing in ShipStation') }}</p>
                        <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $result->missing }}</p>
                    </x-card>
                </section>

                @if (! $result->hasShipStation)
                    <x-alert tone="warn">{{ __('ShipStation is not configured. Shopify refunds are shown without a cross-check.') }}</x-alert>
                @endif
                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: Shopify orders were truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                @if ($result->rows !== [])
                    @include('partials.bulk-ignore-form')
                @endif

                <x-data-table :headers="['Select', 'Order', 'Date', 'Email', 'Refunded', 'ShipStation', 'Risk']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3"><input type="checkbox" name="order_numbers[]" value="{{ $row['order_number'] }}" form="bulk-ignore" aria-label="{{ __('Select order :number', ['number' => $row['order_number']]) }}"></td>
                            <td class="px-4 py-3">{{ $row['order_number'] }}</td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['refunded_amount'], 2) }}</td>
                            <td class="px-4 py-3">{{ $row['shipstation_statuses'] === [] ? '—' : implode(', ', $row['shipstation_statuses']) }}</td>
                            <td class="px-4 py-3">{{ ucfirst($row['risk']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="7">{{ __('No refunded orders found.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
