@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Returns report" title="Return / RMA Tracker" subtitle="Item-level return details with a per-SKU refund summary." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.return-rma.store')" :start-date="$startDate" :end-date="$endDate" submit-label="Scan returns" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results are incomplete: Shopify orders were truncated after :pages pages." :pages="$result->pages">
                <x-slot:heading>{{ count($result->rows) }} refunds from {{ $result->scanned }} orders</x-slot:heading>

                <x-data-table :headers="['Order', 'Refund date', 'Reason', 'Items returned', 'Refund total']" :rows="$result->rows" empty="No returns found.">
                    @foreach ($result->rows as $row)
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
                    @endforeach
                </x-data-table>

                @if ($result->meta['skuStats'] !== [])
                    <h2 class="text-2xl font-bold">{{ __('Return Rate by SKU') }}</h2>
                    <x-data-table
                        :rows="$result->meta['skuStats']"
                        :columns="[
                            'SKU' => ['value' => 'sku', 'class' => 'font-mono'],
                            'Units returned' => 'units',
                            'Return events' => 'events',
                            'Revenue refunded' => fn (array $stat): string => number_format($stat['revenue'], 2),
                        ]"
                    />
                @endif
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
