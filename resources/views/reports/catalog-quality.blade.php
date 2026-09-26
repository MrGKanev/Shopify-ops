@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Catalogue report" title="Catalog quality" subtitle="Find active products with publishing, search visibility, or collection gaps." />

        <x-card>
            <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                <li>{{ __('Checks publication to the Online Store channel.') }}</li>
                <li>{{ __('Checks custom SEO title and description.') }}</li>
                <li>{{ __('Checks whether the product belongs to at least one collection.') }}</li>
            </ul>
        </x-card>

        <form method="POST" action="{{ route('reports.catalog-quality.store') }}">
            @csrf
            <x-button type="submit">Scan active products</x-button>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} active products · {{ count($result->rows) }} with quality issues</h2>
                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results were truncated after :pages product pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif
                <x-data-table :headers="['Product', 'Vendor / type', 'Issues']">
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
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-1">
                                    @foreach ($row['issues'] as $issue)
                                        <x-badge tone="warn" class="w-fit">{{ $issue }}</x-badge>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="3">{{ __('All scanned active products are published, have SEO fields, and belong to a collection.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
