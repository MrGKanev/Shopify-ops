@extends('layouts.app')

@section('content')
    <style @cspNonce>@media print { header, aside, .print-hidden { display: none !important; } main { width: 100%; } body { background: white; color: black; } }</style>
    @php
        $statusLabels = ['first' => __('First order'), 'repeat' => __('Repeat customer'), 'unknown' => __('Unknown')];
        $reasonLabels = [
            'guest_without_email' => __('Guest without an email address; history cannot be matched.'),
            'current_order_not_in_history' => __('The order was not found in the customer history, so its position is unknown.'),
            'history_truncated' => __('Customer history was cut off at the page limit, so a first order cannot be confirmed.'),
            'history_has_undated_orders' => __('Some earlier orders have no usable date, so a first order cannot be confirmed.'),
            'no_prior_orders' => __('No earlier non-cancelled, non-test orders found.'),
            'has_prior_orders' => __('Earlier non-cancelled, non-test orders found.'),
            'vip_threshold' => __('Reached the VIP order count.'),
            'milestone_order' => __('This order is a configured milestone.'),
            'sku_bought_before' => __('An item in this order was bought before.'),
            'history_incomplete' => __('History is incomplete; counts are a minimum.'),
            'lookup_failed' => __('Shopify history could not be loaded.'),
        ];
        $ruleLabels = ['all' => __('All orders'), 'first' => __('First orders only'), 'repeat' => __('Repeat customers'), 'vip' => __('VIP customers'), 'milestone' => __('Milestone orders'), 'sku_repeat' => __('Bought the same item before'), 'unknown' => __('Unknown history')];
    @endphp
    <div class="flex flex-col gap-6">
        <div class="print-hidden"><x-page-header title="{{ __('Customer context') }}" subtitle="{{ __('Show first, repeat and VIP customers for the queued orders. Read-only; no orders are changed.') }}" /></div>

        <x-card class="print-hidden">
            <form class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem_minmax(0,1fr)_auto] sm:items-end" method="GET" action="{{ route('print-queue.customer-context') }}">
                <div>
                    <label class="text-sm font-medium" for="rule">{{ __('Show') }}</label>
                    <select class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="rule" name="rule">
                        @foreach ($ruleLabels as $value => $label)<option value="{{ $value }}" @selected($rule === $value)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium" for="vip">{{ __('VIP from order') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="vip" name="vip" type="number" min="2" max="1000" value="{{ $vipAt }}">
                </div>
                <div>
                    <label class="text-sm font-medium" for="source">{{ __('Orders') }}</label>
                    <select class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="source" name="source">
                        <option value="queue" @selected($source === 'queue')>{{ __('Print queue (:count)', ['count' => $queued]) }}</option>
                        <option value="open" @selected($source === 'open')>{{ __('Open orders (up to :max)', ['max' => $openMax]) }}</option>
                    </select>
                </div>
                <input type="hidden" name="generate" value="1">
                @if ($savedFilter)<input type="hidden" name="filter" value="{{ $savedFilter->id }}">@endif
                <x-button type="submit">{{ __('Check orders') }}</x-button>
            </form>
            @error('rule')<p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
            @error('vip')<p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </x-card>

        <x-card class="print-hidden">
            <h3 class="text-lg font-semibold">{{ __('Saved filters') }}</h3>
            <div class="mt-3 flex flex-wrap gap-3">
                @forelse ($filters as $filter)
                    <span class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-1 dark:border-slate-700">
                        <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('print-queue.customer-context', ['filter' => $filter->id, 'generate' => 1]) }}">{{ $filter->name }}</a>
                        <form method="POST" action="{{ route('print-queue.customer-context.filters.destroy', $filter->id) }}">@csrf @method('DELETE')<button class="text-sm text-red-600 hover:underline dark:text-red-400" type="submit" aria-label="{{ __('Delete') }}">×</button></form>
                    </span>
                @empty
                    <span class="text-sm text-slate-500">{{ __('No saved filters yet.') }}</span>
                @endforelse
            </div>
            <form class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end" method="POST" action="{{ route('print-queue.customer-context.filters.store') }}">
                @csrf
                <input type="hidden" name="rule" value="{{ $rule }}">
                <input type="hidden" name="vip" value="{{ $vipAt }}">
                <div>
                    <label class="text-sm font-medium" for="filter_name">{{ __('Filter name') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="filter_name" name="name" maxlength="80" required value="{{ old('name', $savedFilter?->name) }}">
                    @error('name')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="text-sm font-medium" for="milestones">{{ __('Milestone order numbers (comma separated)') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="milestones" name="milestones" value="{{ old('milestones', implode(', ', $milestones)) }}">
                    @error('milestones.*')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <x-button type="submit">{{ __('Save current filter') }}</x-button>
            </form>
        </x-card>

        @if ($failure === 'configuration')<x-alert tone="warn" class="print-hidden">{{ __('ShipStation is not configured completely for this store.') }}</x-alert>@endif
        @if ($failure === 'lookup')<x-alert tone="error" class="print-hidden">{{ __('The customer context could not be loaded. Try again.') }}</x-alert>@endif
        @if ($source === 'queue' && $queued === 0)<x-empty-state class="print-hidden" title="{{ __('Print queue is empty') }}" />@endif

        @if ($result)
            @if ($openTotal !== null && $openTotal > $openMax)<x-alert tone="warn" class="print-hidden">{{ __('Showing the oldest :shown of :total open orders; the rest were not checked.', ['shown' => $openMax, 'total' => $openTotal]) }}</x-alert>@endif
            @if ($openTotal === 0)<x-alert tone="ok" class="print-hidden">{{ __('No open orders found in ShipStation.') }}</x-alert>@endif
            @if ($result['missing'] !== [])<x-alert tone="warn" class="print-hidden">{{ __('Not found in ShipStation:') }} {{ implode(', ', $result['missing']) }}</x-alert>@endif
            @if ($result['ambiguous'] !== [])<x-alert tone="warn" class="print-hidden">{{ __('More than one exact match, excluded:') }} {{ implode(', ', $result['ambiguous']) }}</x-alert>@endif
            @if ($result['skipped'] !== [])<x-alert tone="warn" class="print-hidden">{{ __('Customer limit reached; not checked:') }} {{ implode(', ', $result['skipped']) }} ({{ __('limit :max customers', ['max' => $max]) }})</x-alert>@endif

            <article class="rounded-xl border border-slate-300 bg-white p-6 text-slate-950">
                <div class="flex items-start justify-between">
                    <div><h2 class="text-2xl font-bold">{{ __('Customer context') }}</h2><p>{{ count($result['rows']) }} / {{ $result['total'] }} {{ __('orders') }}</p></div>
                    <button class="print-hidden rounded-lg bg-slate-900 px-4 py-2 text-white" type="button" data-print-window>{{ __('Print') }}</button>
                </div>
                <table class="mt-6 w-full border-collapse text-sm">
                    <thead><tr class="border-b"><th class="py-2 pr-3 text-left">{{ __('Order') }}</th><th class="py-2 pr-3 text-left">{{ __('Customer') }}</th><th class="py-2 pr-3 text-right">{{ __('Earlier orders') }}</th><th class="py-2 pr-3 text-left">{{ __('Bought before') }}</th><th class="py-2 text-left">{{ __('Why') }}</th></tr></thead>
                    <tbody>
                        @forelse ($result['rows'] as $row)
                            <tr class="border-b align-top">
                                <td class="py-3 pr-3 font-semibold">{{ $row['order'] }}</td>
                                <td class="py-3 pr-3">
                                    <x-badge :tone="$row['status'] === 'unknown' ? 'warn' : ($row['status'] === 'first' ? 'info' : 'ok')">{{ $statusLabels[$row['status']] }}</x-badge>
                                    @if ($row['tier'])<x-badge tone="info">{{ $row['tier'] === 'vip' ? __('VIP') : __('Milestone') }}</x-badge>@endif
                                    <div class="text-xs text-slate-600">{{ $row['email'] !== '' ? $row['email'] : '—' }}</div>
                                </td>
                                <td class="py-3 pr-3 text-right">@if ($row['status'] === 'unknown') — @else {{ $row['atLeast'] ? '≥ ' : '' }}{{ $row['priorOrders'] }} @endif</td>
                                <td class="py-3 pr-3">{{ $row['repeatedSkus'] !== [] ? implode(', ', $row['repeatedSkus']) : '—' }}@if ($row['status'] !== 'unknown' && ! $row['skuHistoryComplete'] && $row['repeatedSkus'] === [])<div class="text-xs text-amber-700">{{ __('Item history incomplete') }}</div>@endif</td>
                                <td class="py-3">@foreach ($row['reasons'] as $reason)<div>{{ $reasonLabels[$reason] ?? $reason }}</div>@endforeach</td>
                            </tr>
                        @empty
                            <tr><td class="py-6 text-center text-slate-500" colspan="5">{{ __('No orders match this filter.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </article>
        @endif
    </div>
@endsection
