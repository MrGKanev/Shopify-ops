@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Fulfillment report" title="Partial Fulfillment Stalls" subtitle="Open partially fulfilled orders whose remaining items have stopped progressing." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form
                :action="route('reports.partial-fulfillment.store')"
                :start-date="$startDate"
                :end-date="$endDate"
                :fields="['threshold' => ['label' => 'Stalled days', 'type' => 'number', 'value' => $threshold, 'min' => 1, 'max' => 365]]"
            />
        </x-slot:form>

        @if ($result)
            <x-report.results
                export-route="reports.partial-fulfillment.export"
                :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate'], 'threshold' => $result->params['threshold']]"
                :truncated="$result->truncated"
                truncated-message="Results are incomplete: Shopify orders were truncated after :pages pages."
                :pages="$result->pages"
            >
                <x-slot:heading>{{ $result->scanned }} partial orders scanned · {{ count($result->rows) }} stalled ≥ {{ $result->params['threshold'] }} days</x-slot:heading>

                <x-data-table :headers="['Order', 'Placed', 'Last fulfillment', 'Stalled', 'Unfulfilled items', 'Email', 'Total']" :rows="$result->rows" empty="No stalled partial fulfillments found.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $row['order_number'] }}</td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['last_fulfilled'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $row['days_stalled'] }} days</td>
                            <td class="px-4 py-3">
                                @foreach ($row['unfulfilled_items'] as $item)
                                    <div>{{ $item['name'] }} @if ($item['sku'])<span class="text-slate-500">· {{ $item['sku'] }}</span>@endif ×{{ $item['qty'] }}</div>
                                @endforeach
                            </td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['total_price'], 2) }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
