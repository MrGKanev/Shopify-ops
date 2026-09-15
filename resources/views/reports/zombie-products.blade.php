@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Catalogue report" title="Zombie products" subtitle="Find active products that cannot be purchased because they have no variants or all tracked variants are out of stock." />

        <x-card>
            <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                <li>Products without variants are always reported.</li>
                <li>Zero-stock checks include only tracked variants with overselling disabled.</li>
                <li>Untracked and continue-selling variants do not make a product a zombie.</li>
            </ul>
        </x-card>

        <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.zombie-products.store') }}">
            @csrf
            <x-button type="submit">Scan active products</x-button>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">Shopify credentials are incomplete for the active store.</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">The report could not be completed. Check Shopify and try again.</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} active products · {{ count($result->rows) }} zombies</h2>
                @if ($result->truncated)
                    <x-alert tone="warn">Results were truncated after {{ $result->pages }} product pages.</x-alert>
                @endif

                <x-data-table :headers="['Product', 'Vendor / type', 'Reason', 'Detail']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">@if ($row['id'])<a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/products/{{ $row['id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['title'] ?: 'Untitled product' }}</a>@else<strong>{{ $row['title'] ?: 'Untitled product' }}</strong>@endif</td>
                            <td class="px-4 py-3">{{ $row['vendor'] ?: '—' }}@if ($row['type']) · {{ $row['type'] }}@endif</td>
                            <td class="px-4 py-3"><x-badge :tone="$row['reason'] === 'no_variants' ? 'danger' : 'warn'">{{ $row['reason'] === 'no_variants' ? 'No variants' : 'Out of stock' }}</x-badge></td>
                            <td class="px-4 py-3">{{ $row['detail'] }}@if ($row['stock'] !== null) · total stock {{ $row['stock'] }}@endif</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="4">All scanned active products have at least one purchasable variant.</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
