@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Fulfillment report" title="Fulfillment SLA Breaches" subtitle="Paid orders exceeding the configured time to first fulfillment." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form
                :action="route('reports.fulfillment-sla.store')"
                :start-date="$startDate"
                :end-date="$endDate"
                :fields="['threshold' => ['label' => 'SLA days', 'type' => 'number', 'value' => $threshold, 'min' => 1, 'max' => 365]]"
            />
        </x-slot:form>

        @if ($result)
            <x-report.results
                export-route="reports.fulfillment-sla.export"
                :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate'], 'threshold' => $result->params['threshold']]"
                :truncated="$result->truncated"
                truncated-message="Results are incomplete: Shopify orders were truncated after :pages pages."
                :pages="$result->pages"
            >
                <x-slot:heading>{{ $result->scanned }} orders scanned · {{ count($result->rows) }} breaches of {{ $result->params['threshold'] }} days</x-slot:heading>

                <x-data-table
                    :rows="$result->rows"
                    empty="No SLA breaches found."
                    :columns="[
                        'Order' => ['value' => 'order_number', 'class' => 'font-semibold'],
                        'Placed' => 'created_at',
                        'Fulfilled' => fn (array $row): string => $row['fulfilled_at'] ?: '—',
                        'Days' => 'days',
                        'Method' => 'method',
                        'Region' => 'region',
                        'Type' => 'order_type',
                        'Status' => fn (array $row): string => $row['financial'].' '.$row['fulfillment'],
                    ]"
                />
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
