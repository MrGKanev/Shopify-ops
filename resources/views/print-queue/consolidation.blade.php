@extends('layouts.app')

@section('content')
    @php
        $stateLabels = ['eligible' => __('Can ship together'), 'review' => __('Needs review'), 'excluded' => __('Excluded'), 'alone' => __('No partner')];
        $stateTones = ['eligible' => 'ok', 'review' => 'warn', 'excluded' => 'danger', 'alone' => 'default'];
        $reasonLabels = [
            'not_awaiting_shipment' => __('Not awaiting shipment (for example on hold).'),
            'manually_excluded' => __('Manually excluded.'),
            'paid_shipping' => __('The customer paid for shipping on this order.'),
            'international_or_customs' => __('Customs data is present.'),
            'insured' => __('Shipment insurance is requested.'),
            'gift' => __('Marked as a gift.'),
            'special_handling' => __('A special handling option is set.'),
            'outside_window' => __('Placed outside the waiting window of the other orders.'),
            'no_compatible_partner' => __('No other eligible order has the same ship-from, service and confirmation.'),
        ];
        $noteLabels = ['service_unspecified' => __('No shipping service is set on these orders; confirm they use the same one.'), 'ship_from_unspecified' => __('No ship-from location is set on these orders; confirm they ship from the same place.')];
    @endphp
    <div class="flex flex-col gap-6">
        <x-page-header title="{{ __('Consolidation review') }}" subtitle="{{ __('Find open orders from the same customer and address that could ship in one package. Review and export only; nothing is merged, held or delayed.') }}" />

        @error('consolidation')<x-alert tone="error">{{ $message }}</x-alert>@enderror
        @if ($failure === 'configuration')<x-alert tone="warn">{{ __('ShipStation is not configured completely for this store.') }}</x-alert>@endif
        @if ($failure === 'lookup')<x-alert tone="error">{{ __('The consolidation review could not be completed. Try again.') }}</x-alert>@endif

        <x-card>
            <form class="grid gap-4 sm:grid-cols-4 sm:items-end" method="GET" action="{{ route('print-queue.consolidation') }}">
                <div>
                    <label class="text-sm font-medium" for="window">{{ __('Waiting window (days)') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="window" name="window" type="number" min="0" max="30" value="{{ $window }}">
                    @error('window')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="text-sm font-medium" for="separate">{{ __('Assumed cost per separate package') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="separate" name="separate" type="number" step="0.01" min="0" value="{{ $separate }}">
                    @error('separate')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="text-sm font-medium" for="combined">{{ __('Assumed cost of one combined package') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="combined" name="combined" type="number" step="0.01" min="0" value="{{ $combined }}">
                    @error('combined')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <input type="hidden" name="generate" value="1">
                <x-button type="submit">{{ __('Find candidates') }}</x-button>
            </form>
            <p class="mt-3 text-sm">{{ __('Orders must share the customer email, recipient and full destination including the second address line, be awaiting shipment, and match on ship-from, service and delivery confirmation. Orders with paid shipping, customs data, insurance, gift or special handling stay out until you mark them reviewed. Savings appear only when you enter both cost assumptions; they are your assumptions, not carrier quotes, and package sizes are not known.') }}</p>
        </x-card>

        @if ($result)
            <x-alert tone="warn">{{ __('Read from ShipStation at :time. Checked :count open orders.', ['time' => $result['read_at'], 'count' => $result['considered']]) }}</x-alert>
            @if ($result['total_groups'] > count($result['groups']))<x-alert tone="warn">{{ __('Showing :shown of :total groups.', ['shown' => count($result['groups']), 'total' => $result['total_groups']]) }}</x-alert>@endif
            @if ($result['groups'] === [])
                <x-empty-state title="{{ __('No customer has more than one open order to the same address') }}" />
            @else
                <div><x-button variant="ghost" :href="route('print-queue.consolidation.export', ['window' => $window])">{{ __('Download CSV') }}</x-button></div>
            @endif
            @foreach ($result['groups'] as $group)
                <x-card>
                    <h2 class="text-lg font-bold">{{ $group['email'] }}</h2>
                    <p class="text-sm">{{ trim($group['recipient']) }} · {{ str_replace('|', ', ', $group['destination']) }}</p>
                    @foreach ($group['bundles'] as $bundle)
                        <div class="mt-4 rounded-lg border border-emerald-300 p-3 dark:border-emerald-800">
                            <p class="font-semibold">{{ __('Could ship together') }}: {{ implode(' + ', $bundle['orders']) }} · {{ trans_choice('{1} :count package avoided|[2,*] :count packages avoided', $bundle['packages_avoided']) }}</p>
                            <p class="text-sm">{{ __('Combined weight') }}: {{ $bundle['weight'] ? $bundle['weight']['value'].' '.$bundle['weight']['units'] : __('Unknown') }} · {{ __('Package size') }}: {{ __('Unknown') }} · {{ __('Estimated saving') }}: {{ $bundle['savings'] ?? __('Unknown') }}</p>
                            @foreach ($bundle['notes'] as $note)<p class="text-xs">{{ $noteLabels[$note] }}</p>@endforeach
                            @if ($bundle['unidentified_lines'])<p class="text-xs">{{ __('Some lines have no SKU and are combined by item name only.') }}</p>@endif
                            <table class="mt-2 w-full border-collapse text-sm">
                                <thead><tr class="border-b"><th class="py-1 pr-3 text-left">{{ __('SKU') }}</th><th class="py-1 pr-3 text-left">{{ __('Item') }}</th><th class="py-1 pr-3 text-right">{{ __('Quantity') }}</th><th class="py-1 text-left">{{ __('From orders') }}</th></tr></thead>
                                <tbody>@foreach ($bundle['lines'] as $line)<tr class="border-b"><td class="py-1 pr-3">{{ $line['sku'] !== '' ? $line['sku'] : '—' }}</td><td class="py-1 pr-3">{{ $line['name'] }}</td><td class="py-1 pr-3 text-right">{{ $line['quantity'] }}</td><td class="py-1">@foreach ($line['allocations'] as $order => $quantity){{ $order }} × {{ $quantity }}@if (! $loop->last), @endif @endforeach</td></tr>@endforeach</tbody>
                            </table>
                        </div>
                    @endforeach
                    <table class="mt-4 w-full border-collapse text-sm">
                        <thead><tr class="border-b"><th class="py-2 pr-3 text-left">{{ __('Order') }}</th><th class="py-2 pr-3 text-left">{{ __('Result') }}</th><th class="py-2 pr-3 text-left">{{ __('Why') }}</th><th class="py-2 text-left">{{ __('Decision') }}</th></tr></thead>
                        <tbody>
                            @foreach ($group['members'] as $member)
                                <tr class="border-b align-top">
                                    <td class="py-2 pr-3 font-semibold">{{ $member['number'] }}<div class="text-xs font-normal">{{ __($member['status']) }}@if ($member['created_at']) · {{ $member['created_at']->toDateString() }}@endif</div></td>
                                    <td class="py-2 pr-3"><x-badge :tone="$stateTones[$member['state']]">{{ $stateLabels[$member['state']] }}</x-badge></td>
                                    <td class="py-2 pr-3 text-xs">@forelse ($member['reasons'] as $reason)<div>{{ $reasonLabels[$reason] ?? $reason }}</div>@empty — @endforelse</td>
                                    <td class="py-2">
                                        <form class="flex flex-wrap gap-2" method="POST" action="{{ route('print-queue.consolidation.decide') }}">
                                            @csrf
                                            <input type="hidden" name="order_number" value="{{ $member['number'] }}">
                                            <input type="hidden" name="window" value="{{ $window }}">
                                            @if ($member['decision'] === null)
                                                <button class="text-sm text-indigo-600 hover:underline dark:text-indigo-400" name="decision" value="excluded" type="submit">{{ __('Exclude') }}</button>
                                                @if (in_array('paid_shipping', $member['reasons'], true) || in_array('international_or_customs', $member['reasons'], true) || in_array('insured', $member['reasons'], true) || in_array('gift', $member['reasons'], true) || in_array('special_handling', $member['reasons'], true))
                                                    <button class="text-sm text-indigo-600 hover:underline dark:text-indigo-400" name="decision" value="reviewed" type="submit">{{ __('Mark reviewed') }}</button>
                                                @endif
                                            @else
                                                <span class="text-xs">{{ $member['decision'] === 'excluded' ? __('Excluded by you') : __('Reviewed by you') }}</span>
                                                <button class="text-sm text-red-600 hover:underline dark:text-red-400" name="decision" value="clear" type="submit">{{ __('Undo') }}</button>
                                            @endif
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-card>
            @endforeach
        @endif
    </div>
@endsection
