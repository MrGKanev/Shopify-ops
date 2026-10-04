@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Fulfillment report" title="Post-Ship Address Changes" subtitle="Shipping addresses edited after the first fulfillment was created." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.post-ship-address-changes.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results
                export-route="reports.post-ship-address-changes.export"
                :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate']]"
                :truncated="$result->truncated"
                truncated-message="Results are incomplete: events were truncated after :pages pages."
                :pages="$result->pages"
            >
                <x-slot:heading>{{ count($result->rows) }} post-ship changes</x-slot:heading>

                <x-data-table :headers="['Order', 'Placed', 'First fulfillment', 'Changed', 'After shipment', 'Email', 'Current address', 'Total', 'Status']" :rows="$result->rows" empty="No post-ship address changes found.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $row['order_number'] }}</td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['fulfillment_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['changed_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['mins_after_ship'] }} minutes</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3"><strong>{{ $row['addr_name'] }}</strong><br>{{ $row['addr_line'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['total'], 2) }}</td>
                            <td class="px-4 py-3">{{ $row['financial'] }} {{ $row['fulfillment'] }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
