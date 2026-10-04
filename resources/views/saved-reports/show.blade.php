@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header :eyebrow="$report->report_date->toDateString()" title="{{ __('Run Audit snapshot') }}" :subtitle="$report->start_date->toDateString().' → '.$report->end_date->toDateString()" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-2xl font-bold">{{ $report->rows_found }} {{ __('missing') }}</h2>
            <div class="flex flex-wrap items-center gap-2">
                <x-button variant="ghost" :href="route('saved-reports.export', $report)">{{ __('Download CSV') }}</x-button>
                <form method="POST" action="{{ route('reports.run-audit.store') }}">
                    @csrf
                    <input type="hidden" name="start_date" value="{{ \App\Support\UiFormat::date($report->report_date) }}">
                    <input type="hidden" name="end_date" value="{{ \App\Support\UiFormat::date($report->report_date) }}">
                    <x-button type="submit" variant="ghost">{{ __('Re-run this audit') }}</x-button>
                </form>
            </div>
        </div>

        @if ($history->isNotEmpty())
            <x-card>
                <h2 class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-300">{{ __('Last') }} {{ \App\Support\UiFormat::count($history->count(), 'audits') }}</h2>
                <div class="flex items-end gap-2" style="height: 60px">
                    @php($max = max(1, $history->max('rows_found')))
                    @foreach ($history as $point)
                        <a class="flex flex-col items-center gap-1" href="{{ route('saved-reports.show', $point) }}" title="{{ \App\Support\UiFormat::date($point->report_date) }}: {{ $point->rows_found }} missing">
                            <div class="rounded-t bg-indigo-300 dark:bg-indigo-700" style="width: 16px; height: {{ max(4, (int) ($point->rows_found / $max * 50)) }}px; @if ($point->is($report)) background: #4f46e5; @endif"></div>
                        </a>
                    @endforeach
                </div>
            </x-card>
        @endif

        <x-data-table :headers="['Order', 'Date', 'Email', 'Total', 'Recurrence', '']">
            @forelse ($report->result['missing'] ?? [] as $order)
                @php($number = ltrim((string) ($order['name'] ?? $order['order_number'] ?? ''), '#'))
                @php($count = $recurrenceCounts[$number] ?? 0)
                <tr>
                    <td class="px-4 py-3 font-semibold">{{ $order['name'] ?? $order['order_number'] ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $order['created_at'] ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $order['email'] ?? '—' }}</td>
                    <td class="px-4 py-3">{{ \App\Support\UiFormat::number((float) ($order['total_price'] ?? 0), 2) }}</td>
                    <td class="px-4 py-3">
                        @if ($count >= 3)
                            <x-badge tone="danger">{{ __('Hot ·') }} {{ $count }}</x-badge>
                        @elseif ($count >= 2)
                            <x-badge tone="warn">{{ __('Recurring ·') }} {{ $count }}</x-badge>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            @if ($number !== '')
                                <a class="text-indigo-600 dark:text-indigo-400" href="{{ route('orders.spot-check', ['order_number' => $number]) }}">{{ __('Spot-check') }}</a>
                                <a class="text-indigo-600 dark:text-indigo-400" href="{{ route('orders.timeline', ['order_number' => $number]) }}">{{ __('Timeline') }}</a>
                            @endif
                            @if (! empty($order['id']))
                                <a class="text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $order['id'] }}" target="_blank" rel="noopener noreferrer">Shopify</a>
                            @endif
                            @if ($number !== '')
                                <a class="text-indigo-600 dark:text-indigo-400" href="https://app.shipstation.com/#!/orders/all-orders-search-result?quickSearch={{ urlencode($number) }}" target="_blank" rel="noopener noreferrer">ShipStation</a>
                                <form method="POST" action="{{ route('ignored-orders.store') }}">
                                    @csrf
                                    <input type="hidden" name="order_number" value="{{ $number }}">
                                    <x-button type="submit" size="sm" variant="danger">{{ __('Ignore') }}</x-button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="6">{{ __('No missing orders.') }}</td>
                </tr>
            @endforelse
        </x-data-table>
    </div>
@endsection
