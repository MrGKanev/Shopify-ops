@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Fulfillment report" title="{{ __('Fulfilled Without Tracking') }}" subtitle="Fulfillments missing a tracking number after the grace period." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form
                :action="route('reports.no-tracking.store')"
                :start-date="$startDate"
                :end-date="$endDate"
                :fields="['threshold' => ['label' => 'Grace period (hours)', 'type' => 'number', 'value' => $threshold, 'min' => 1, 'max' => 8760]]"
            />
        </x-slot:form>

        @if ($result)
            <x-report.results
                export-route="reports.no-tracking.export"
                :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate'], 'threshold' => $result->params['threshold']]"
                :truncated="$result->truncated"
                truncated-message="Results are incomplete: Shopify orders were truncated after :pages pages."
                :pages="$result->pages"
            >
                <x-slot:heading>{{ $result->scanned }} {{ __('fulfilled orders scanned ·') }} {{ count($result->rows) }} {{ __('missing tracking after') }} {{ $result->params['threshold'] }}h</x-slot:heading>

                <x-data-table :remediation-actions="['update_tracking' => 'Add tracking']" :headers="['Order', 'Placed', 'Fulfillment', 'Hours since', 'Carrier', 'Email', 'Total']" :rows="$result->rows" empty="All fulfillments have tracking.">
                    @foreach ($result->rows as $row)
                        @foreach ($row['missing'] as $missing)
                            <tr>
                                <td class="px-4 py-3 font-semibold">{{ $row['order_number'] }}</td>
                                <td class="px-4 py-3">{{ \App\Support\UiFormat::date($row['created_at']) }}</td>
                                <td class="px-4 py-3">{{ $missing['created_at'] }}</td>
                                <td class="px-4 py-3">{{ $missing['hours_ago'] }}h</td>
                                <td class="px-4 py-3">{{ $missing['company'] ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $row['email'] }}</td>
                                <td class="px-4 py-3">{{ \App\Support\UiFormat::number($row['total'], 2) }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
