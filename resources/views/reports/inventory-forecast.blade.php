@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Inventory report" title="Inventory forecast" subtitle="Estimate days until stock reaches zero from the last 30 days of paid-order sales." />

        <x-card>
            <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                <li>Only tracked variants with overselling disabled are included.</li>
                <li>Daily rate equals units sold in 30 days divided by 30.</li>
                <li>Critical means fewer than 7 days; low stock means 7–13 days.</li>
            </ul>
        </x-card>

        <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.inventory-forecast.store') }}">
            @csrf
            <x-button type="submit">Run forecast</x-button>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">Shopify credentials are incomplete for the active store.</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">The forecast could not be completed. Check Shopify and try again.</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->products }} products · {{ $result->variants }} variants · {{ $result->orders }} orders</h2>
                <div class="flex flex-wrap gap-2 text-sm">
                    <span>{{ $result->startDate }} → {{ $result->endDate }}</span>
                    @if ($result->critical)
                        <x-badge tone="danger">{{ $result->critical }} critical</x-badge>
                    @endif
                    @if ($result->warning)
                        <x-badge tone="warn">{{ $result->warning }} low stock</x-badge>
                    @endif
                </div>
                @if ($result->productsTruncated || $result->ordersTruncated)
                    <x-alert tone="warn">
                        Results are incomplete.
                        @if ($result->productsTruncated)
                            Product catalogue stopped after {{ $result->productPages }} pages.
                        @endif
                        @if ($result->ordersTruncated)
                            Orders stopped after {{ $result->orderPages }} pages.
                        @endif
                    </x-alert>
                @endif

                <x-data-table :headers="['SKU', 'Product / variant', 'Stock', 'Sold', 'Daily rate', 'Days to zero']">
                    @forelse ($result->rows as $row)
                        <tr class="{{ $row['days_to_zero'] !== null && $row['days_to_zero'] < 7 ? 'bg-red-50 dark:bg-red-950/30' : ($row['days_to_zero'] !== null && $row['days_to_zero'] < 14 ? 'bg-amber-50 dark:bg-amber-950/30' : '') }}">
                            <td class="px-4 py-3 font-mono">{{ $row['sku'] ?: '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($row['product_id'])
                                    <a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/products/{{ $row['product_id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['product_title'] ?: 'Untitled product' }}</a>
                                @else
                                    <strong>{{ $row['product_title'] ?: 'Untitled product' }}</strong>
                                @endif
                                <div class="text-slate-500">{{ $row['variant_title'] ?: 'Default' }}</div>
                            </td>
                            <td class="px-4 py-3">{{ $row['stock'] }}</td>
                            <td class="px-4 py-3">{{ $row['sold_30d'] }}</td>
                            <td class="px-4 py-3 font-mono">{{ number_format($row['daily_rate'], 2) }}/day</td>
                            <td class="px-4 py-3 font-semibold">
                                @if ($row['stock'] <= 0)
                                    Out of stock
                                @elseif ($row['days_to_zero'] !== null)
                                    {{ $row['days_to_zero'] }} days
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">No stock-out risk was detected.</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
