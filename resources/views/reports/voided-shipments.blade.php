@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Fulfillment report"
        title="{{ __('Voided Shipments') }}"
        subtitle="ShipStation shipments voided in the selected period."
        :configuration-error="$configurationError"
        credentials-message="ShipStation credentials are incomplete for the active store."
        :report-failed="$reportFailed"
        failure-message="The report could not be completed. Check ShipStation and try again."
    >
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.voided-shipments.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        <x-alert tone="warn">{{ __('Voiding does not prove payment or a refund. Only imported label-refund transactions count as received; refund expectations and due dates are set explicitly in Shipping Billing Audit.') }}</x-alert>
        <div><x-button variant="ghost" :href="route('shipping-billing.index')">{{ __('Shipping Billing Audit') }}</x-button></div>
        @if ($result)
            <x-report.results export-route="reports.voided-shipments.export" :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate']]">
                <x-slot:heading>{{ \App\Support\UiFormat::count(count($result->rows), 'voided shipments') }}</x-slot:heading>

                <x-data-table :headers="['Order', 'Void date', 'Ship date', 'Carrier / service', 'Tracking', 'Ship to', 'Refund billing']" :rows="$result->rows" empty="No voided shipments found.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $row['order_number'] }}</td>
                            <td class="px-4 py-3">{{ $row['void_date'] }}</td>
                            <td class="px-4 py-3">{{ $row['ship_date'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ strtoupper($row['carrier']) ?: '—' }}{{ $row['service'] ? ' / '.$row['service'] : '' }}</td>
                            <td class="px-4 py-3">{{ $row['tracking'] ?: '—' }}</td>
                            <td class="px-4 py-3"><strong>{{ $row['ship_to_name'] }}</strong><br>{{ implode(', ', array_filter([$row['ship_to_city'], $row['ship_to_state'], $row['ship_to_zip'], $row['ship_to_country']])) }}</td>
                            <td class="px-4 py-3">@if ($billing = $row['refund_billing'] ?? null)<div>{{ __($billing['refund_status']) }} · {{ $billing['currency'] }}</div><div>{{ __('Received label refunds') }}: {{ $billing['refunds'] }}</div><div>{{ __('Outstanding refund') }}: {{ $billing['outstanding_refund'] ?? __('Unknown') }}</div>@else{{ __('No unambiguous imported billing match.') }}@endif</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
