@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Fulfillment report"
        title="{{ __('Voided Shipments') }}"
        subtitle="ShipStation shipments voided in the selected period."
        :configuration-error="$configurationError"
        credentials-message="ShipStation credentials are incomplete for the active store."
        :report-failed="$reportFailed"
        failure-message="The report could not be completed. Check ShipStation and try again."
    >
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.voided-shipments.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results export-route="reports.voided-shipments.export" :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate']]">
                <x-slot:heading>{{ \App\Support\UiFormat::count(count($result->rows), 'voided shipments') }}</x-slot:heading>

                <x-data-table :headers="['Order', 'Void date', 'Ship date', 'Carrier / service', 'Tracking', 'Ship to']" :rows="$result->rows" empty="No voided shipments found.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $row['order_number'] }}</td>
                            <td class="px-4 py-3">{{ $row['void_date'] }}</td>
                            <td class="px-4 py-3">{{ $row['ship_date'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ strtoupper($row['carrier']) ?: '—' }}{{ $row['service'] ? ' / '.$row['service'] : '' }}</td>
                            <td class="px-4 py-3">{{ $row['tracking'] ?: '—' }}</td>
                            <td class="px-4 py-3"><strong>{{ $row['ship_to_name'] }}</strong><br>{{ implode(', ', array_filter([$row['ship_to_city'], $row['ship_to_state'], $row['ship_to_zip'], $row['ship_to_country']])) }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
