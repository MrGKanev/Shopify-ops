@extends('layouts.app')

@section('content')
    @php
        $stateLabels = ['different' => __('Items differ'), 'address' => __('Address differs'), 'unknown' => __('Unknown'), 'match' => __('Matches')];
        $stateTones = ['different' => 'danger', 'address' => 'warn', 'unknown' => 'warn', 'match' => 'ok'];
        $reasonLabels = [
            'shopify_order_not_found' => __('The order was not found in Shopify.'),
            'shopify_order_ambiguous' => __('More than one Shopify order has this number.'),
            'shopify_lines_incomplete' => __('Shopify fulfillment lines could not be read completely.'),
            'shopify_fulfillment_orders_unavailable' => __('No readable Shopify fulfillment orders; the location may be inaccessible to this app.'),
            'lines_without_sku' => __('Some lines have no SKU, so quantities cannot be confirmed.'),
        ];
        $flagLabels = [
            'shopify_cancelled' => __('Cancelled in Shopify but still open in ShipStation.'),
            'split_in_shipstation' => __('Several open ShipStation orders share this number; quantities are compared as a combined total.'),
            'weight_review' => __('Items changed: re-check ShipStation weight and package.'),
            'customs_review' => __('Items changed: re-check the customs declaration.'),
        ];
        $stateOptions = ['issues' => __('Orders that need review'), 'all' => __('All checked orders'), 'different' => __('Items differ'), 'address' => __('Address differs'), 'unknown' => __('Unknown'), 'match' => __('Matches')];
    @endphp
    <div class="flex flex-col gap-6">
        <x-page-header title="{{ __('Pre-shipment drift check') }}" subtitle="{{ __('Compare open ShipStation orders with what Shopify still needs shipped, before picking or printing. Read-only; nothing is changed or merged.') }}" />

        <x-card>
            <form class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end" method="GET" action="{{ route('print-queue.drift-audit') }}">
                <div>
                    <label class="text-sm font-medium" for="state">{{ __('Show') }}</label>
                    <select class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="state" name="state">
                        @foreach ($stateOptions as $value => $label)<option value="{{ $value }}" @selected($state === $value)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <input type="hidden" name="generate" value="1">
                <x-button type="submit">{{ __('Check open orders') }}</x-button>
            </form>
            <p class="mt-3 text-sm">{{ __('Checks the oldest :max open ShipStation orders (awaiting shipment or on hold). Every run reads both systems again. ShipStation can update modified orders itself: if the Use Fulfillable Quantity option solves the difference, prefer that setting.', ['max' => $max]) }}</p>
        </x-card>

        @if ($failure === 'configuration')<x-alert tone="warn">{{ __('ShipStation is not configured completely for this store.') }}</x-alert>@endif
        @if ($failure === 'lookup')<x-alert tone="error">{{ __('The drift check could not be completed. Try again.') }}</x-alert>@endif

        @if ($result)
            @if ($result['total'] > $result['checked'])<x-alert tone="warn">{{ __('Showing the oldest :shown of :total open orders; the rest were not checked.', ['shown' => $result['checked'], 'total' => $result['total']]) }}</x-alert>@endif
            @if ($result['total'] === 0)<x-alert tone="ok">{{ __('No open orders found in ShipStation.') }}</x-alert>@endif
            <x-alert tone="warn">{{ __('A current difference between the two systems does not prove who changed an order or when. Read at: ShipStation :shipstation, Shopify :shopify.', ['shipstation' => $result['shipstation_read_at'], 'shopify' => $result['shopify_read_at']]) }}</x-alert>
            <x-card>
                <p class="mb-3 text-sm">{{ __('Checked :checked orders: :different with item differences, :address with address differences, :unknown unknown, :match matching.', ['checked' => $result['checked'], 'different' => $result['counts']['different'] ?? 0, 'address' => $result['counts']['address'] ?? 0, 'unknown' => $result['counts']['unknown'] ?? 0, 'match' => $result['counts']['match'] ?? 0]) }}</p>
                @if ($result['rows'] === [] && $result['total'] > 0)<x-empty-state title="{{ __('Nothing to show for this filter') }}" />@else
                <table class="w-full border-collapse text-sm">
                    <thead><tr class="border-b"><th class="py-2 pr-3 text-left">{{ __('Order') }}</th><th class="py-2 pr-3 text-left">{{ __('Result') }}</th><th class="py-2 pr-3 text-left">{{ __('ShipStation ships more than Shopify needs') }}</th><th class="py-2 pr-3 text-left">{{ __('Shopify needs more than ShipStation has') }}</th><th class="py-2 pr-3 text-left">{{ __('Address') }}</th><th class="py-2 text-left">{{ __('Notes') }}</th></tr></thead>
                    <tbody>
                        @foreach ($result['rows'] as $row)
                            <tr class="border-b align-top">
                                <td class="py-3 pr-3 font-semibold">{{ $row['number'] }}<div class="text-xs font-normal">{{ implode(', ', array_map('__', $row['statuses'])) }}@if ($row['shipstation_orders'] > 1) · {{ $row['shipstation_orders'] }} {{ __('ShipStation orders') }}@endif</div></td>
                                <td class="py-3 pr-3"><x-badge :tone="$stateTones[$row['state']]">{{ $stateLabels[$row['state']] }}</x-badge></td>
                                <td class="py-3 pr-3">@forelse ($row['extra'] as $sku => $quantity)<div>{{ $sku }} × {{ $quantity }}</div>@empty — @endforelse</td>
                                <td class="py-3 pr-3">@forelse ($row['missing'] as $sku => $quantity)<div>{{ $sku }} × {{ $quantity }}</div>@empty — @endforelse</td>
                                <td class="py-3 pr-3">@forelse ($row['address_changes'] as $change)<div><span class="font-medium">{{ str_replace('_', ' ', $change['field']) }}</span> ({{ $change['kind'] === 'formatting' ? __('formatting only') : __('differs') }}): {{ $change['shopify'] !== '' ? $change['shopify'] : '—' }} → {{ $change['shipstation'] !== '' ? $change['shipstation'] : '—' }}</div>@empty — @endforelse</td>
                                <td class="py-3 text-xs">@foreach ($row['reasons'] as $reason)<div>{{ $reasonLabels[$reason] ?? $reason }}</div>@endforeach @foreach ($row['flags'] as $flag)<div>{{ $flagLabels[$flag] ?? $flag }}</div>@endforeach</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </x-card>
        @endif
    </div>
@endsection
