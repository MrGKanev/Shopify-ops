@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Risk report"
        title="Refunds Tracker"
        subtitle="Refunded Shopify orders cross-checked against ShipStation status."
        :configuration-errors="[
            'Shopify credentials are incomplete for the active store.' => $shopifyConfigurationError,
            'ShipStation credentials are incomplete for the active store.' => $shipStationConfigurationWarning,
        ]"
        :report-failed="$reportFailed"
        failure-message="The report could not be completed. Check the integrations and try again."
    >
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.refund-tracker.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results>
                <section class="grid gap-3 sm:grid-cols-3">
                    <x-card padding="p-4">
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Refunded orders') }}</p>
                        <p class="text-2xl font-bold">{{ $result->scanned }}</p>
                    </x-card>
                    <x-card padding="p-4">
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Still active in ShipStation') }}</p>
                        <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ $result->meta['active'] }}</p>
                    </x-card>
                    <x-card padding="p-4">
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Missing in ShipStation') }}</p>
                        <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $result->meta['missing'] }}</p>
                    </x-card>
                </section>

                @if (! $result->meta['hasShipStation'])
                    <x-alert tone="warn">{{ __('ShipStation is not configured. Shopify refunds are shown without a cross-check.') }}</x-alert>
                @endif
                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: Shopify orders were truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                @if ($result->rows !== [])
                    @include('partials.bulk-ignore-form')
                @endif

                <x-data-table :headers="['Select', 'Order', 'Date', 'Email', 'Refunded', 'ShipStation', 'Risk']" :rows="$result->rows" empty="No refunded orders found.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <x-report.ignore-checkbox :number="$row['order_number']" />
                            <td class="px-4 py-3">{{ $row['order_number'] }}</td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['refunded_amount'], 2) }}</td>
                            <td class="px-4 py-3">{{ $row['shipstation_statuses'] === [] ? '—' : implode(', ', $row['shipstation_statuses']) }}</td>
                            <td class="px-4 py-3">{{ ucfirst($row['risk']) }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
