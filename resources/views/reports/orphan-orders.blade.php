@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Risk report"
        title="{{ __('Orphan Detector') }}"
        subtitle="ShipStation orders without a matching Shopify order."
        :configuration-error="$configurationError"
        credentials-message="Shopify and ShipStation credentials are required for the active store."
        :report-failed="$reportFailed"
        failure-message="The report could not be completed. Check both integrations and try again."
    >
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.orphan-orders.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results
                export-route="reports.orphan-orders.export"
                :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate']]"
                :truncated="$result->meta['shopifyTruncated']"
                truncated-message="Results are incomplete: Shopify orders were truncated after :pages pages."
                :pages="$result->meta['shopifyPages']"
            >
                <x-slot:heading>{{ $result->meta['shipStationTotal'] }} {{ __('ShipStation vs') }} {{ $result->meta['shopifyTotal'] }} {{ __('Shopify orders ·') }} {{ \App\Support\UiFormat::count(count($result->rows), 'orphans') }}</x-slot:heading>

                <x-data-table :remediation-actions="['tag_shipstation' => 'Tag in ShipStation']"
                    :rows="$result->rows"
                    empty="No orphan orders found."
                    :columns="[
                        'Order' => ['value' => 'order_number', 'class' => 'font-semibold'],
                        'Date' => 'order_date',
                        'Customer' => fn (array $row): string => $row['customer'] ?: '—',
                        'Email' => fn (array $row): string => $row['email'] ?: '—',
                        'Status' => fn (array $row): string => __($row['order_status']),
                        'Total' => fn (array $row): string => \App\Support\UiFormat::number($row['total'], 2),
                    ]"
                />
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
