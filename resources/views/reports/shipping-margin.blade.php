@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Fulfillment report"
        title="{{ __('Shipping Margin Erosion') }}"
        subtitle="ShipStation label cost compared with shipping charged in Shopify."
        :configuration-error="$configurationError"
        credentials-message="Shopify or ShipStation credentials are incomplete for the active store."
        :report-failed="$reportFailed"
        failure-message="The report could not be completed. Check Shopify and ShipStation, then try again."
    >
        <x-slot:form>
            <x-report.date-range-form
                :action="route('reports.shipping-margin.store')"
                :start-date="$startDate"
                :end-date="$endDate"
                :fields="['threshold' => ['label' => 'Loss threshold', 'type' => 'number', 'value' => $threshold, 'min' => 1, 'step' => '0.01']]"
            />
        </x-slot:form>

        @if ($result)
            <x-report.results
                export-route="reports.shipping-margin.export"
                :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate'], 'threshold' => $result->params['threshold']]"
                :truncated="$result->meta['shopifyTruncated']"
                truncated-message="Results are incomplete: Shopify orders were truncated after :pages pages."
                :pages="$result->meta['shopifyPages']"
            >
                <x-slot:heading>{{ $result->scanned }} {{ __('shipments scanned ·') }} {{ count($result->rows) }} {{ __('losses over $') }}{{ \App\Support\UiFormat::number($result->params['threshold'], 2) }}</x-slot:heading>

                @if ($result->meta['byCarrier'])
                    <x-data-table
                        :rows="$result->meta['byCarrier']"
                        :columns="[
                            'Carrier' => ['value' => 'carrier', 'class' => 'font-semibold'],
                            'Orders' => 'count',
                            'Total loss' => fn (array $row): string => '$'.\App\Support\UiFormat::number($row['total_loss'], 2),
                            'Average loss' => fn (array $row): string => '$'.\App\Support\UiFormat::number($row['avg_loss'], 2),
                        ]"
                    />
                @endif

                <x-data-table
                    :rows="$result->rows"
                    empty="No margin-eroding shipments found."
                    :columns="[
                        'Order' => ['value' => 'order_number', 'class' => 'font-semibold'],
                        'Ship date' => 'ship_date',
                        'Carrier / service' => fn (array $row): string => $row['carrier'].($row['service'] ? ' / '.$row['service'] : ''),
                        'Ship cost' => fn (array $row): string => '$'.\App\Support\UiFormat::number($row['ship_cost'], 2),
                        'Charged' => fn (array $row): string => '$'.\App\Support\UiFormat::number($row['shipping_charged'], 2),
                        'Loss' => ['value' => fn (array $row): string => '$'.\App\Support\UiFormat::number($row['loss'], 2), 'class' => 'font-semibold text-red-600 dark:text-red-400'],
                        'Email' => fn (array $row): string => $row['email'] ?: '—',
                        'Total' => fn (array $row): string => '$'.\App\Support\UiFormat::number($row['total'], 2),
                    ]"
                />
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
