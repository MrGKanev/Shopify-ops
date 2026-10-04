@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Fulfillment report"
        title="Shipment Aging"
        subtitle="Live ShipStation awaiting-shipment orders older than the threshold."
        :configuration-error="$configurationError"
        credentials-message="ShipStation credentials are incomplete for the active store."
        :report-failed="$reportFailed"
        failure-message="The report could not be completed. Check ShipStation and try again."
    >
        <x-slot:form>
            <x-report.params-form
                :action="route('reports.shipment-aging.store')"
                :fields="['threshold' => ['label' => 'Older than days', 'type' => 'number', 'value' => $threshold, 'min' => 1, 'max' => 365]]"
            />
        </x-slot:form>

        @if ($result)
            <x-report.results export-route="reports.shipment-aging.export" :export-params="['threshold' => $result->params['threshold']]">
                <x-slot:heading>{{ $result->scanned }} awaiting orders scanned · {{ count($result->rows) }} older than {{ $result->params['threshold'] }} days</x-slot:heading>

                <div class="grid gap-4 md:grid-cols-2">
                    <x-card padding="p-4">
                        <h3 class="font-bold">{{ __('By SKU') }}</h3>
                        @foreach (array_slice($result->meta['bySku'], 0, 8) as $row)
                            <p>{{ $row['sku'] }} · {{ $row['orders'] }} orders · {{ $row['qty'] }} qty · oldest {{ $row['oldest_days'] }}d</p>
                        @endforeach
                    </x-card>
                    <x-card padding="p-4">
                        <h3 class="font-bold">{{ __('By type') }}</h3>
                        @foreach (array_slice($result->meta['byType'], 0, 8) as $row)
                            <p>{{ $row['type'] }} · {{ $row['orders'] }} orders · oldest {{ $row['oldest_days'] }}d</p>
                        @endforeach
                    </x-card>
                </div>

                <x-data-table :headers="['Order', 'Date', 'Days', 'Customer', 'Total', 'Type', 'SKUs']" :rows="$result->rows" empty="No aging shipments.">
                    @foreach ($result->rows as $row)
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
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
