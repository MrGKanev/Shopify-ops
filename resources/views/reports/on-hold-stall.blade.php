@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Fulfillment report"
        title="{{ __('On-Hold Stall') }}"
        subtitle="Shopify fulfillment orders currently on hold, sorted by time since placement."
        :configuration-error="$configurationError"
        :report-failed="$reportFailed"
        failure-message="The report could not be completed. Check Shopify access scopes and try again."
    >
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.on-hold-stall.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results
                export-route="reports.on-hold-stall.export"
                :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate']]"
                :truncated="$result->truncated"
                truncated-message="Results are incomplete: Shopify fulfillment orders were truncated after :pages pages."
                :pages="$result->pages"
            >
                <x-slot:heading>{{ \App\Support\UiFormat::count($result->scanned, 'on-hold fulfillment orders') }}</x-slot:heading>

                <x-data-table
                    :rows="$result->rows"
                    empty="No on-hold fulfillment orders found."
                    :columns="[
                        'Order' => ['value' => 'order_number', 'class' => 'font-semibold'],
                        'Placed' => 'created_at',
                        'Waiting' => fn (array $row): string => $row['days_waiting'].' '.__('days'),
                        'Hold reason' => fn (array $row): string => $row['hold_reason'] ?: '—',
                        'Notes' => fn (array $row): string => $row['hold_notes'] ?: '—',
                        'Email' => 'email',
                        'Total' => fn (array $row): string => \App\Support\UiFormat::number($row['total'], 2),
                        'Financial' => 'financial',
                    ]"
                />
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
