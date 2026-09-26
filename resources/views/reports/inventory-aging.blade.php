@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Inventory report" title="Inventory aging" subtitle="Find active, tracked variants at zero or negative stock that still sold during the selected period." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.inventory-aging.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end">
                <x-button type="submit">{{ __('Run report') }}</x-button>
            </div>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->products }} products · {{ $result->variants }} variants · {{ $result->orders }} orders</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ count($result->rows) }} zero-stock recent sellers</p>
                @if ($result->productsTruncated || $result->ordersTruncated)
                    <x-alert tone="warn">
                        {{ __('Results are incomplete:') }}
                        @if ($result->productsTruncated)
                            {{ __('Product catalogue truncated after :pages pages.', ['pages' => $result->productPages]) }}
                        @endif
                        @if ($result->productsTruncated && $result->ordersTruncated)
                            {{ __('and') }}
                        @endif
                        @if ($result->ordersTruncated)
                            {{ __('Orders truncated after :pages pages.', ['pages' => $result->orderPages]) }}
                        @endif
                        .
                    </x-alert>
                @endif

                <x-data-table :headers="['Product / variant', 'SKU', 'Stock', 'Recent quantity', 'Last sale', 'Action']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">
                                @if ($row['product_id'])
                                    <a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/products/{{ $row['product_id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['product_title'] ?: 'Untitled product' }}</a>
                                @else
                                    <strong>{{ $row['product_title'] ?: 'Untitled product' }}</strong>
                                @endif
                                <div class="text-slate-500">{{ $row['variant_title'] ?: 'Default' }}</div>
                            </td>
                            <td class="px-4 py-3 font-mono">{{ $row['sku'] }}</td>
                            <td class="px-4 py-3">{{ $row['stock'] }}</td>
                            <td class="px-4 py-3 font-semibold">{{ $row['recent_qty'] }}</td>
                            <td class="px-4 py-3">{{ $row['last_date'] ?: '—' }}<div class="text-slate-500">{{ $row['last_order'] }}</div></td>
                            <td class="px-4 py-3">
                                @if ($row['last_order'])
                                    <a class="text-indigo-600 dark:text-indigo-400" href="{{ route('orders.spot-check', ['prefill' => ltrim($row['last_order'], '#')]) }}">Spot-check</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">{{ __('No active tracked zero-stock variant had recent sales in the selected window.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
