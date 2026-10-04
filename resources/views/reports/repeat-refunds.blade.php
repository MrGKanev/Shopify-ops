@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Risk report" title="Repeat Refunds" subtitle="Customers with multiple refunded orders." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form
                :action="route('reports.repeat-refunds.store')"
                :start-date="$startDate"
                :end-date="$endDate"
                :fields="['minimum' => ['label' => 'Minimum refunds', 'type' => 'number', 'value' => $minimum, 'min' => 2, 'max' => 100]]"
            />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} scanned · {{ count($result->rows) }} repeat customers</x-slot:heading>

                <x-data-table :headers="['Email', 'Refund count', 'Total refunded', 'Orders']" :rows="$result->rows" empty="No repeat refund customers found.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ $row['refund_count'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['total_refunded'], 2) }}</td>
                            <td class="px-4 py-3">
                                @foreach ($row['orders'] as $order)
                                    <span>{{ $order['order_number'] }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
