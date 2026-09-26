@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Returns report" title="Return / RMA Tracker" subtitle="Item-level return details with a per-SKU refund summary." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.return-rma.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="text-sm text-red-600">{{ __($message) }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end">
                <x-button type="submit">Scan returns</x-button>
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
                <h2 class="text-2xl font-bold">{{ count($result->rows) }} refunds from {{ $result->scanned }} orders</h2>

                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: Shopify orders were truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                <x-data-table :headers="['Order', 'Refund date', 'Reason', 'Items returned', 'Refund total']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">{{ $row['order_number'] }}</td>
                            <td class="px-4 py-3">{{ $row['refund_date'] }}</td>
                            <td class="px-4 py-3">{{ $row['reason'] === '' ? '—' : $row['reason'] }}</td>
                            <td class="px-4 py-3">
                                @forelse ($row['items'] as $item)
                                    <div>{{ $item['quantity'] }}× {{ $item['name'] }} @if ($item['sku'] !== '')<span class="font-mono text-slate-500">({{ $item['sku'] }})</span>@endif</div>
                                @empty
                                    <span class="text-slate-500">{{ __('No line items') }}</span>
                                @endforelse
                            </td>
                            <td class="px-4 py-3">{{ number_format($row['refund_total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="5">{{ __('No returns found.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>

                @if ($result->skuStats !== [])
                    <h2 class="text-2xl font-bold">{{ __('Return Rate by SKU') }}</h2>
                    <x-data-table :headers="['SKU', 'Units returned', 'Return events', 'Revenue refunded']">
                        @foreach ($result->skuStats as $stat)
                            <tr>
                                <td class="px-4 py-3 font-mono">{{ $stat['sku'] }}</td>
                                <td class="px-4 py-3">{{ $stat['units'] }}</td>
                                <td class="px-4 py-3">{{ $stat['events'] }}</td>
                                <td class="px-4 py-3">{{ number_format($stat['revenue'], 2) }}</td>
                            </tr>
                        @endforeach
                    </x-data-table>
                @endif
            </section>
        @endif
    </div>
@endsection
