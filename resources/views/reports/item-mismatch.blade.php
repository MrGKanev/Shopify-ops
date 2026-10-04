@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Fulfillment report"
        title="{{ __('Shipped Item Mismatch') }}"
        subtitle="ShipStation shipped SKU quantities compared with Shopify ordered quantities."
        :configuration-error="$configurationError"
        credentials-message="Shopify and ShipStation credentials are required for the active store."
        :report-failed="$reportFailed"
        failure-message="The report could not be completed. Check both integrations and try again."
    >
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.item-mismatch.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results
                export-route="reports.item-mismatch.export"
                :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate']]"
                :truncated="$result->meta['shopifyTruncated']"
                truncated-message="Results are incomplete: Shopify orders were truncated after :pages pages."
                :pages="$result->meta['shopifyPages']"
            >
                <x-slot:heading>{{ $result->scanned }} {{ __('ShipStation orders scanned ·') }} {{ \App\Support\UiFormat::count(count($result->rows), 'mismatches') }}</x-slot:heading>

                <x-data-table :headers="['Order', 'Date', 'Email', 'Type', 'Missing', 'Extra', 'Missing required', 'Total']" :rows="$result->rows" empty="Everything shipped matches what was ordered.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $row['order_number'] }}</td>
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::date($row['created_at']) }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ $row['order_type'] }}</td>
                            <td class="px-4 py-3">
                                @foreach ($row['missing'] as $sku => $qty)
                                    <div>{{ $sku }} ×{{ $qty }}</div>
                                @endforeach
                            </td>
                            <td class="px-4 py-3">
                                @foreach ($row['extra'] as $sku => $qty)
                                    <div>{{ $sku }} ×{{ $qty }}</div>
                                @endforeach
                            </td>
                            <td class="px-4 py-3">
                                @foreach ($row['missing_required'] as $label)
                                    <div>{{ __($label) }}</div>
                                @endforeach
                            </td>
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::number($row['total'], 2) }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
