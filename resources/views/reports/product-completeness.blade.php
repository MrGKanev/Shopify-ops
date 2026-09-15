@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Catalogue report" title="Product completeness" subtitle="Find active products with no usable image, meaningful description, variants, or SKU." />

        <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.product-completeness.store') }}">
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
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} with issues</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $result->critical }} critical · {{ $result->warnings }} warnings</p>

                @if ($result->truncated)
                    <x-alert tone="warn">Results were truncated after {{ $result->pages }} product pages. The report is not a complete store inventory.</x-alert>
                @endif

                <x-data-table :headers="['Product', 'Vendor / type', 'Images', 'Variants', 'Issues', 'Severity']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">
                                @if ($row['id'])
                                    <a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/products/{{ $row['id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['title'] ?: 'Untitled product' }}</a>
                                @else
                                    <strong>{{ $row['title'] ?: 'Untitled product' }}</strong>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $row['vendor'] ?: '—' }}@if ($row['type']) · {{ $row['type'] }}@endif</td>
                            <td class="px-4 py-3">{{ $row['images'] }}</td>
                            <td class="px-4 py-3">{{ $row['variants'] }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-1">
                                    @foreach ($row['issues'] as $issue)
                                        <span>{{ $issue['message'] }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-4 py-3"><x-badge :tone="$row['severity'] === 'critical' ? 'danger' : 'warn'">{{ $row['severity'] }}</x-badge></td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">All scanned active products are complete.</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
