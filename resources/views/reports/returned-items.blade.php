@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Returns report" title="Returned Items Report" subtitle="Returned quantities grouped by product for refunds issued in the selected period." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.returned-items.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results
                export-route="reports.returned-items.export"
                :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate']]"
                :truncated="$result->truncated"
                truncated-message="Results are incomplete: Shopify orders were truncated after :pages pages."
                :pages="$result->pages"
            >
                <x-slot:heading>{{ $result->scanned }} orders scanned · {{ count($result->rows) }} products</x-slot:heading>

                <x-data-table :rows="$result->rows" empty="No returned items found." :columns="['Product' => ['value' => 'product', 'class' => 'font-semibold'], 'Quantity' => 'quantity']" />
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
