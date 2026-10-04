@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Fulfillment report"
        title="{{ __('SS Shipped / Shopify Unfulfilled') }}"
        subtitle="ShipStation shipped orders still unfulfilled or partial in Shopify."
        :configuration-error="$configurationError"
        credentials-message="Shopify and ShipStation credentials are required for the active store."
        :report-failed="$reportFailed"
        failure-message="The report could not be completed. Check both integrations and try again."
    >
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.shipped-unfulfilled.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results
                export-route="reports.shipped-unfulfilled.export"
                :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate']]"
                :truncated="$result->meta['shopifyTruncated']"
                truncated-message="Results are incomplete: Shopify orders were truncated after :pages pages."
                :pages="$result->meta['shopifyPages']"
            >
                <x-slot:heading>{{ $result->meta['shippedTotal'] }} {{ __('SS shipped orders ·') }} {{ count($result->rows) }} {{ __('Shopify sync mismatches') }}</x-slot:heading>

                <x-data-table
                    :rows="$result->rows"
                    empty="All shipped orders are synced."
                    :columns="[
                        'Order' => ['value' => 'order_number', 'class' => 'font-semibold'],
                        'Date' => 'order_date',
                        'Customer' => fn (array $row): string => $row['customer'] ?: '—',
                        'Email' => fn (array $row): string => $row['email'] ?: '—',
                        'SS status' => fn (): string => __('shipped'),
                        'Shopify status' => 'sh_fulfillment',
                        'Total' => fn (array $row): string => \App\Support\UiFormat::number($row['total'], 2),
                    ]"
                />
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
