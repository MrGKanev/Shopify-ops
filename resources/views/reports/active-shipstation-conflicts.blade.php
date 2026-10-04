@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Fulfillment report"
        title="Active SS Conflicts"
        subtitle="Refunded or cancelled Shopify orders still active in ShipStation."
        :configuration-error="$configurationError"
        credentials-message="Shopify and ShipStation credentials are required for the active store."
        :report-failed="$reportFailed"
        failure-message="The report could not be completed. Check both integrations and try again."
    >
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.active-shipstation-conflicts.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results
                export-route="reports.active-shipstation-conflicts.export"
                :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate']]"
                :truncated="$result->meta['shopifyTruncated']"
                truncated-message="Results are incomplete: Shopify orders were truncated after :pages pages."
                :pages="$result->meta['shopifyPages']"
            >
                <x-slot:heading>{{ $result->scanned }} Shopify exceptions vs {{ $result->meta['activeShipStation'] }} active SS orders · {{ count($result->rows) }} conflicts</x-slot:heading>

                <x-data-table
                    :rows="$result->rows"
                    empty="No active conflicts found."
                    :columns="[
                        'Order' => ['value' => 'order_number', 'class' => 'font-semibold'],
                        'Issue' => 'issue',
                        'Shopify date' => 'created_at',
                        'Email' => 'email',
                        'Total' => fn (array $row): string => number_format($row['total'], 2),
                        'SS status' => fn (array $row): string => str_replace('_', ' ', $row['ss_status']),
                        'SS date' => 'ss_date',
                        'SS total' => fn (array $row): string => number_format($row['ss_total'], 2),
                    ]"
                />
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
