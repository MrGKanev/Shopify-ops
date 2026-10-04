@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Risk report" title="Address Changes" subtitle="Orders whose shipping address was edited after placement." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.address-changes.store')" :start-date="$startDate" :end-date="$endDate">
                <x-slot:actions>
                    <x-button type="submit" variant="ghost" formaction="{{ route('reports.address-changes.export') }}">{{ __('Download CSV') }}</x-button>
                </x-slot:actions>
            </x-report.date-range-form>
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results are incomplete: events truncated after :pages pages." :pages="$result->pages">
                <x-slot:heading>{{ count($result->rows) }} orders with address changes</x-slot:heading>

                <x-data-table :headers="['Order', 'Placed', 'Changed', 'Time gap', 'Email', 'Current shipping address', 'Total', 'Status']" :rows="$result->rows" empty="No address changes found.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">
                                @if ($row['shopify_id'] !== '')
                                    <a class="text-indigo-600 dark:text-indigo-400" href="https://admin.shopify.com/store/{{ rawurlencode($activeStore->shopify_store) }}/orders/{{ $row['shopify_id'] }}">{{ $row['order_number'] }}</a>
                                @else
                                    {{ $row['order_number'] }}
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['changed_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['gap_mins'] }} minutes</td>
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
