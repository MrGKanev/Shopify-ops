@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Catalogue report" title="Zombie products" subtitle="Find active products that cannot be purchased because they have no variants or all tracked variants are out of stock." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-card>
                <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>{{ __('Products without variants are always reported.') }}</li>
                    <li>{{ __('Zero-stock checks include only tracked variants with overselling disabled.') }}</li>
                    <li>{{ __('Untracked and continue-selling variants do not make a product a zombie.') }}</li>
                </ul>
            </x-card>

            <x-report.params-form :action="route('reports.zombie-products.store')" submit-label="Scan active products" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results were truncated after :pages product pages." :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} active products · {{ count($result->rows) }} zombies</x-slot:heading>

                <x-data-table :headers="['Product', 'Vendor / type', 'Reason', 'Detail']" :rows="$result->rows" empty="All scanned active products have at least one purchasable variant.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">@if ($row['id'])<a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/products/{{ $row['id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['title'] ?: 'Untitled product' }}</a>@else<strong>{{ $row['title'] ?: 'Untitled product' }}</strong>@endif</td>
                            <td class="px-4 py-3">{{ $row['vendor'] ?: '—' }}@if ($row['type']) · {{ $row['type'] }}@endif</td>
                            <td class="px-4 py-3"><x-badge :tone="$row['reason'] === 'no_variants' ? 'danger' : 'warn'">{{ $row['reason'] === 'no_variants' ? 'No variants' : 'Out of stock' }}</x-badge></td>
                            <td class="px-4 py-3">{{ $row['detail'] }}@if ($row['stock'] !== null) · total stock {{ $row['stock'] }}@endif</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
