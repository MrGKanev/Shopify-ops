@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Inventory report"
        title="Inventory oversell risk"
        subtitle="Compare active Shopify stock with every ShipStation order awaiting shipment and find SKUs that cannot cover current demand."
        :configuration-errors="[
            'Shopify credentials are incomplete for the active store.' => $shopifyConfigurationError,
            'ShipStation credentials are incomplete for the active store.' => $shipStationConfigurationError,
        ]"
        :report-failed="$reportFailed"
        failure-message="The report could not be completed. Check Shopify and ShipStation, then try again."
    >
        <x-slot:form>
            <x-report.params-form :action="route('reports.inventory-oversell.store')" submit-label="Scan inventory" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->meta['productsTruncated']" truncated-message="Results are incomplete: product catalogue truncated after :pages pages." :pages="$result->meta['productPages']">
                <x-slot:heading>{{ $result->meta['products'] }} products · {{ $result->meta['awaitingOrders'] }} awaiting orders</x-slot:heading>
                <x-slot:summary>{{ count($result->rows) }} SKUs at risk of overselling</x-slot:summary>

                <x-data-table :headers="['Product / variant', 'SKU', 'Stock', 'Awaiting', 'Shortfall', 'Action']" :rows="$result->rows" empty="Current tracked stock covers every SKU awaiting shipment.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">
                                <x-report.shopify-link resource="products" :id="$row['product_id']" class="font-semibold">{{ $row['product_title'] ?: 'Untitled product' }}</x-report.shopify-link>
                                @if ($row['variant_title'])
                                    <div class="text-slate-500">{{ $row['variant_title'] }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono">{{ $row['sku'] }}</td>
                            <td class="px-4 py-3">{{ $row['stock'] }}</td>
                            <td class="px-4 py-3">{{ $row['awaiting'] }}</td>
                            <td class="px-4 py-3 font-bold text-red-600 dark:text-red-400">{{ $row['shortfall'] }}</td>
                            <td class="px-4 py-3">
                                @if ($row['duplicate_sku'])
                                    <a class="text-indigo-600 dark:text-indigo-400" href="{{ route('reports.sku-duplicates') }}">{{ __('Review duplicates') }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
