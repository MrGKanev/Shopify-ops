@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Catalogue report" title="SKU duplicates" subtitle="Find repeated SKUs across active, draft, and archived products. Blank SKUs are ignored; matching is case-sensitive." />

        <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.sku-duplicates.store') }}">
            @csrf
            <x-button type="submit">Scan all products</x-button>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">Shopify credentials are incomplete for the active store.</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">The report could not be completed. Check Shopify and try again.</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} duplicate SKUs</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $result->totalVariants }} variants scanned</p>
                @if ($result->truncated)
                    <x-alert tone="warn">Results were truncated after {{ $result->pages }} product pages. The report is not a complete store inventory.</x-alert>
                @endif

                <x-data-table :headers="['SKU', 'Count', 'Products / variants']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-mono">{{ $row['sku'] }}</td>
                            <td class="px-4 py-3">{{ $row['count'] }}</td>
                            <td class="px-4 py-3">
                                <ul class="flex flex-col gap-2">
                                    @foreach ($row['variants'] as $variant)
                                        <li>@if ($variant['product_id'])<a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/products/{{ $variant['product_id'] }}" target="_blank" rel="noopener noreferrer">{{ $variant['product_title'] ?: 'Untitled product' }}</a>@else<strong>{{ $variant['product_title'] ?: 'Untitled product' }}</strong>@endif · {{ $variant['variant_title'] ?: 'Default' }} · {{ $variant['product_status'] }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="3">No duplicate SKUs found in the scanned products.</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
