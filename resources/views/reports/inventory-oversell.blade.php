@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Inventory report" title="Inventory oversell risk" subtitle="Compare active Shopify stock with every ShipStation order awaiting shipment and find SKUs that cannot cover current demand." />

        <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.inventory-oversell.store') }}">
            @csrf
            <x-button type="submit">Scan inventory</x-button>
        </form>

        @if ($shopifyConfigurationError)
            <x-alert tone="warn">Shopify credentials are incomplete for the active store.</x-alert>
        @endif
        @if ($shipStationConfigurationError)
            <x-alert tone="warn">ShipStation credentials are incomplete for the active store.</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">The report could not be completed. Check Shopify and ShipStation, then try again.</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->products }} products · {{ $result->awaitingOrders }} awaiting orders</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ count($result->rows) }} SKUs at risk of overselling</p>
                @if ($result->productsTruncated)
                    <x-alert tone="warn">Results are incomplete: product catalogue truncated after {{ $result->productPages }} pages.</x-alert>
                @endif

                <x-data-table :headers="['Product / variant', 'SKU', 'Stock', 'Awaiting', 'Shortfall', 'Action']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">
                                @if ($row['product_id'])
                                    <a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/products/{{ $row['product_id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['product_title'] ?: 'Untitled product' }}</a>
                                @else
                                    <strong>{{ $row['product_title'] ?: 'Untitled product' }}</strong>
                                @endif
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
                                    <a class="text-indigo-600 dark:text-indigo-400" href="{{ route('reports.sku-duplicates') }}">Review duplicates</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">Current tracked stock covers every SKU awaiting shipment.</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
