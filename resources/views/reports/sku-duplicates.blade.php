@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Catalogue report" title="SKU duplicates" subtitle="Find repeated SKUs across active, draft, and archived products. Blank SKUs are ignored; matching is case-sensitive." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.params-form :action="route('reports.sku-duplicates.store')" submit-label="Scan all products" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results were truncated after :pages product pages. The report is not a complete store inventory." :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} scanned · {{ count($result->rows) }} duplicate SKUs</x-slot:heading>
                <x-slot:summary>{{ $result->meta['totalVariants'] }} variants scanned</x-slot:summary>

                <x-data-table :headers="['SKU', 'Count', 'Products / variants']" :rows="$result->rows" empty="No duplicate SKUs found in the scanned products.">
                    @foreach ($result->rows as $row)
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
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
