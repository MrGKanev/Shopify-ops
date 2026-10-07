@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="{{ __('Rate quotes for :order', ['order' => $snapshot->order_number]) }}">
            <x-button size="sm" variant="ghost" :href="route('reports.rate-shopping', ['order_number' => $snapshot->order_number])">{{ __('New capture') }}</x-button>
            <x-button size="sm" variant="ghost" :href="route('reports.rate-shopping.result', $snapshot->id)">{{ __('Refresh status') }}</x-button>
        </x-page-header>
        @if ($errors->any())<x-alert tone="error"><ul class="list-disc pl-5">@foreach ($errors->all() as $message)<li>{{ __($message) }}</li>@endforeach</ul></x-alert>@endif
        <x-card>
            <h2 class="text-xl font-bold">{{ $snapshot->mode === 'recorded_decision' ? __('Recorded quote decision') : __('Current-tariff simulation') }}</h2>
            <p class="mt-2 text-sm">{{ __('Status') }}: {{ __($snapshot->status) }}</p>
            @if ($snapshot->message)<p class="mt-2 text-sm">{{ __($snapshot->message) }}</p>@endif
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('A saved simulation uses tariffs retrieved at the displayed time. Use New capture for fresh rates.') }}</p>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Captured quote differences are not proof of invoiced or historical shipment savings. No label is purchased by this workflow.') }}</p>
            @if ($snapshot->quoted_at)<p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Rates actually retrieved at :time', ['time' => \App\Support\UiFormat::date($snapshot->quoted_at, true)]) }}</p>@endif
            @if ($snapshot->selected_at)<p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Service selection confirmed at :time', ['time' => \App\Support\UiFormat::date($snapshot->selected_at, true)]) }}</p>@endif
        </x-card>
        @if ($snapshot->context)
            <x-card>
                <h2 class="text-lg font-semibold">{{ __('Recorded comparison conditions') }}</h2>
                @foreach (['from' => 'Origin', 'to' => 'Destination'] as $key => $label)
                    <p class="mt-2 text-sm">{{ __($label) }}: {{ implode(', ', array_filter(array_map(fn ($field) => (string) ($snapshot->context[$key][$field] ?? ''), ['street1', 'street2', 'street3', 'city', 'state', 'postalCode', 'country']))) }}</p>
                @endforeach
                <p class="mt-2 text-sm">{{ __('Capture day: :date (:timezone); V1 does not price a custom ship date.', ['date' => $snapshot->context['pricing_date'], 'timezone' => $snapshot->context['timezone']]) }}</p>
                <p class="mt-2 text-sm">{{ $snapshot->context['carrier'] }} · {{ $snapshot->context['package_code'] }} · {{ $snapshot->context['weight']['value'] }} {{ $snapshot->context['weight']['units'] }} · {{ $snapshot->context['dimensions']['length'] }} × {{ $snapshot->context['dimensions']['width'] }} × {{ $snapshot->context['dimensions']['height'] }} {{ $snapshot->context['dimensions']['units'] }}</p>
                <p class="mt-2 text-sm">{{ __('Approved transit limit: :days days; included coverage: :coverage :currency', ['days' => $snapshot->context['max_transit_days'], 'coverage' => $snapshot->context['minimum_coverage'], 'currency' => $snapshot->context['currency']]) }}</p>
                <p class="mt-2 text-sm">{{ $snapshot->context['to']['residential'] ? __('Residential destination') : __('Commercial destination') }} · {{ $snapshot->context['tracking_required'] ? __('Tracking required') : __('Tracking not required') }} · {{ __('Delivery confirmation') }}: {{ $snapshot->context['confirmation'] }}</p>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Eligibility and account currency are operator-confirmed; V1 does not independently verify transit or coverage. Totals include both returned transport cost and otherCost, excluding later carrier adjustments.') }}</p>
            </x-card>
        @endif
        @if ($snapshot->quotes !== null)
            <div class="grid gap-3 sm:grid-cols-3">
                <x-stat-tile label="Least quoted total among approved services" :value="$comparison['cheapest'] === null ? '—' : \App\Support\UiFormat::number($comparison['cheapest']['total'], 2).' '.$snapshot->context['currency']" />
                <x-stat-tile :label="$snapshot->mode === 'recorded_decision' ? 'Selected quote' : 'Configured service repriced at capture time'" :value="$comparison['reference'] === null ? '—' : \App\Support\UiFormat::number($comparison['reference']['total'], 2).' '.$snapshot->context['currency']" />
                <x-stat-tile :label="$snapshot->mode === 'recorded_decision' ? 'Quoted difference at recorded selection' : 'Simulated quoted difference'" :value="$comparison['difference'] === null ? '—' : \App\Support\UiFormat::number($comparison['difference'], 2).' '.$snapshot->context['currency']" />
            </div>
            <x-data-table :headers="['Service', 'Transport', 'Other quoted costs', 'Total', 'Eligibility']" :rows="$snapshot->quotes" empty="No rates were returned.">
                @foreach ($snapshot->quotes as $quote)
                    <tr><td class="px-4 py-3">{{ $quote['service_name'] }}<div class="text-xs text-slate-500 dark:text-slate-400">{{ $quote['service_code'] }}</div></td><td class="px-4 py-3">{{ \App\Support\UiFormat::number($quote['shipment_cost'], 2) }}</td><td class="px-4 py-3">{{ \App\Support\UiFormat::number($quote['other_cost'], 2) }}</td><td class="px-4 py-3">{{ \App\Support\UiFormat::number($quote['total'], 2) }} {{ $snapshot->context['currency'] }}</td><td class="px-4 py-3"><x-badge :tone="$quote['eligible'] ? 'ok' : 'warn'">{{ $quote['eligible'] ? __('Operator-approved') : __('Excluded: service not approved') }}</x-badge></td></tr>
                @endforeach
            </x-data-table>
        @endif
        @if ($canSelect)
            <x-card>
                <form method="POST" action="{{ route('rate-quote-selections.store', $snapshot->id) }}" class="flex flex-col gap-4">
                    @csrf
                    <label class="flex flex-col gap-2 text-sm font-medium">{{ __('Choose an approved quoted service') }}<select name="service_code" required class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950">@foreach ($snapshot->quotes as $quote)@if ($quote['eligible'])<option value="{{ $quote['service_code'] }}">{{ $quote['service_name'] }} · {{ \App\Support\UiFormat::number($quote['total'], 2) }} {{ $snapshot->context['currency'] }}</option>@endif @endforeach</select></label>
                    <p class="text-sm">{{ __('Applying this selection updates the open ShipStation order with the captured carrier, service, warehouse, parcel weight/dimensions, package and confirmation settings. Addresses and billing details are retained; residential classification follows this capture.') }}</p>
                    <label class="flex items-start gap-2 text-sm"><input name="confirmed" type="checkbox" value="1" required>{{ __('I reviewed these conditions and approve applying the service selection in ShipStation. Quotes must be no more than five minutes old.') }}</label>
                    <div><x-button type="submit">{{ __('Apply service and record decision') }}</x-button></div>
                </form>
            </x-card>
        @endif
    </div>
@endsection
