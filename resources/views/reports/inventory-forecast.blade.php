@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Inventory report" title="Inventory forecast" subtitle="Estimate days until stock reaches zero from the last 30 days of paid-order sales." :configuration-error="$configurationError" :report-failed="$reportFailed" failure-message="The forecast could not be completed. Check Shopify and try again.">
        <x-slot:form>
            <x-card>
                <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>{{ __('Only tracked variants with overselling disabled are included.') }}</li>
                    <li>{{ __('Daily rate equals units sold in 30 days divided by 30.') }}</li>
                    <li>{{ __('Critical means fewer than 7 days; low stock means 7–13 days.') }}</li>
                </ul>
            </x-card>

            <x-report.params-form :action="route('reports.inventory-forecast.store')" submit-label="Run forecast" />
        </x-slot:form>

        @if ($result)
            <x-report.results>
                <x-slot:heading>{{ $result->meta['products'] }} products · {{ $result->meta['variants'] }} variants · {{ $result->meta['orders'] }} orders</x-slot:heading>

                <div class="flex flex-wrap gap-2 text-sm">
                    <span>{{ $result->params['startDate'] }} → {{ $result->params['endDate'] }}</span>
                    @if ($result->meta['critical'])
                        <x-badge tone="danger">{{ $result->meta['critical'] }} critical</x-badge>
                    @endif
                    @if ($result->meta['warning'])
                        <x-badge tone="warn">{{ $result->meta['warning'] }} low stock</x-badge>
                    @endif
                </div>
                @if ($result->meta['productsTruncated'] || $result->meta['ordersTruncated'])
                    <x-alert tone="warn">
                        {{ __('Results are incomplete.') }}
                        @if ($result->meta['productsTruncated'])
                            {{ __('Product catalogue stopped after :pages pages.', ['pages' => $result->meta['productPages']]) }}
                        @endif
                        @if ($result->meta['ordersTruncated'])
                            {{ __('Orders stopped after :pages pages.', ['pages' => $result->meta['orderPages']]) }}
                        @endif
                    </x-alert>
                @endif

                <x-data-table :headers="['SKU', 'Product / variant', 'Stock', 'Sold', 'Daily rate', 'Days to zero']" :rows="$result->rows" empty="No stock-out risk was detected.">
                    @foreach ($result->rows as $row)
                        <tr class="{{ $row['days_to_zero'] !== null && $row['days_to_zero'] < 7 ? 'bg-red-50 dark:bg-red-950/30' : ($row['days_to_zero'] !== null && $row['days_to_zero'] < 14 ? 'bg-amber-50 dark:bg-amber-950/30' : '') }}">
                            <td class="px-4 py-3 font-mono">{{ $row['sku'] ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <x-report.shopify-link resource="products" :id="$row['product_id']" class="font-semibold">{{ $row['product_title'] ?: 'Untitled product' }}</x-report.shopify-link>
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
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
