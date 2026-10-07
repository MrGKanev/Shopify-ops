@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Returns report" title="{{ __('Return / RMA Tracker') }}" subtitle="Overdue requests, received items awaiting processing and unfinished exchanges." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Returns checks require read_returns access and warehouse checks need location-read access (such as read_locations). Warehouse receipt requires a Shopify disposition with a location; a return label or carrier scan alone is not receipt confirmation.') }}</p>
            <x-report.date-range-form :action="route('reports.return-rma.store')" :start-date="$startDate" :end-date="$endDate" submit-label="Scan returns" />
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('The date range selects when the return was created, including returns on older orders. Refund history uses its existing order-based date range.') }}</p>
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results are incomplete: some Shopify data was truncated after :pages pages. Unseen or incomplete returns do not resolve existing issues." :pages="$result->pages">
                <x-slot:heading>{{ count($result->rows) }} {{ __('return exceptions from') }} {{ $result->scanned }} {{ __('returns') }}</x-slot:heading>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Deadlines: approval :approval, processing :processing, exchange :exchange business days.', ['approval' => $result->meta['policy']['approval_days'], 'processing' => $result->meta['policy']['processing_days'], 'exchange' => $result->meta['policy']['exchange_days']]) }} {{ __('Business days are Monday to Friday in the shop timezone. Public holidays are not excluded.') }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Checked at :time', ['time' => \App\Support\UiFormat::date($result->meta['checkedAt'], true)]) }}</p>
                <x-data-table :headers="['Return', 'Order', 'Next action', 'Due', 'Quantity', 'Evidence']" :rows="$result->rows" empty="No overdue return steps were found.">
                    @foreach ($result->rows as $row)
                        <tr id="return-{{ basename($row['return_id']) }}-{{ $row['kind'] }}">
                            <td class="px-4 py-3 font-semibold">{{ $row['return_name'] }}<div class="text-xs text-slate-500 dark:text-slate-400">{{ $row['return_status'] }}</div></td>
                            <td class="px-4 py-3"><x-report.shopify-link :id="$row['shopify_id']">{{ $row['order_number'] }}</x-report.shopify-link></td>
                            <td class="px-4 py-3">{{ __($row['next_action']) }}</td>
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::date($row['due_at'], true) }}</td>
                            <td class="px-4 py-3">{{ $row['quantity'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $row['receipt_confirmed'] ? __('Shopify warehouse disposition; processing remains') : ($row['kind'] === 'approval_overdue' ? __('Return request awaiting a decision') : __('Physical exchange items awaiting fulfillment')) }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
                <x-button size="sm" variant="ghost" :href="route('operational-issues.index')">{{ __('Open issue triage') }}</x-button>
                <details>
                    <summary class="cursor-pointer text-lg font-semibold">{{ __('Refund history') }} · {{ count($result->meta['refundRows']) }} {{ __('refunds from') }} {{ \App\Support\UiFormat::count($result->meta['refundOrderCount'], 'orders') }}</summary>
                    <p class="my-3 text-sm text-slate-500 dark:text-slate-400">{{ __('A refund without a return can be legitimate and is not flagged by these checks.') }}</p>
                <x-data-table :headers="['Order', 'Refund date', 'Reason', 'Refunded items', 'Refund total']" :rows="($result->meta['refundRows'] ?? [])" empty="No refunds found.">
                    @foreach (($result->meta['refundRows'] ?? []) as $row)
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
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::number($row['refund_total'], 2) }}</td>
                        </tr>
                    @endforeach
                </x-data-table>

                @if ($result->meta['skuStats'] !== [])
                    <h2 class="text-2xl font-bold">{{ __('Refunded units by SKU') }}</h2>
                    <x-data-table
                        :rows="$result->meta['skuStats']"
                        :columns="[
                            'SKU' => ['value' => 'sku', 'class' => 'font-mono'],
                            'Units refunded' => 'units',
                            'Refund events' => 'events',
                            'Revenue refunded' => fn (array $stat): string => \App\Support\UiFormat::number($stat['revenue'], 2),
                        ]"
                    />
                @endif
                </details>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
