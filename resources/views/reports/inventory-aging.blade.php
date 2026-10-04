@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Inventory report" title="{{ __('Inventory aging') }}" subtitle="Find active, tracked variants at zero or negative stock that still sold during the selected period." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.inventory-aging.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results>
                <x-slot:heading>{{ $result->meta['products'] }} {{ __('products ·') }} {{ $result->meta['variants'] }} {{ __('variants ·') }} {{ \App\Support\UiFormat::count($result->meta['orders'], 'orders') }}</x-slot:heading>
                <x-slot:summary>{{ count($result->rows) }} {{ __('zero-stock recent sellers') }}</x-slot:summary>

                @if ($result->meta['productsTruncated'] || $result->meta['ordersTruncated'])
                    <x-alert tone="warn">
                        {{ __('Results are incomplete:') }}
                        @if ($result->meta['productsTruncated'])
                            {{ __('Product catalogue truncated after :pages pages.', ['pages' => $result->meta['productPages']]) }}
                        @endif
                        @if ($result->meta['productsTruncated'] && $result->meta['ordersTruncated'])
                            {{ __('and') }}
                        @endif
                        @if ($result->meta['ordersTruncated'])
                            {{ __('Orders truncated after :pages pages.', ['pages' => $result->meta['orderPages']]) }}
                        @endif
                        .
                    </x-alert>
                @endif

                <x-data-table :headers="['Product / variant', 'SKU', 'Stock', 'Recent quantity', 'Last sale', 'Action']" :rows="$result->rows" empty="No active tracked zero-stock variant had recent sales in the selected window.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">
                                <x-report.shopify-link resource="products" :id="$row['product_id']" class="font-semibold">{{ $row['product_title'] ?: __('Untitled product') }}</x-report.shopify-link>
                                <div class="text-slate-500">{{ $row['variant_title'] ?: __('Default') }}</div>
                            </td>
                            <td class="px-4 py-3 font-mono">{{ $row['sku'] }}</td>
                            <td class="px-4 py-3">{{ $row['stock'] }}</td>
                            <td class="px-4 py-3 font-semibold">{{ $row['recent_qty'] }}</td>
                            <td class="px-4 py-3">{{ $row['last_date'] ?: '—' }}<div class="text-slate-500">{{ $row['last_order'] }}</div></td>
                            <td class="px-4 py-3">
                                @if ($row['last_order'])
                                    <a class="text-indigo-600 dark:text-indigo-400" href="{{ route('orders.spot-check', ['prefill' => ltrim($row['last_order'], '#')]) }}">{{ __('Spot-check') }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
