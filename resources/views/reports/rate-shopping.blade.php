@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="{{ __('Rate Shopping Audit') }}" subtitle="Capture current quotes before choosing a service, or inspect a clearly labelled current-tariff simulation." />
        <x-card>
            <p class="text-sm">{{ __('V1 quotes cover domestic default-account transport pricing, including shipmentCost and otherCost. Additional insurance, DDP, international routes and special billing are not comparable here.') }}</p>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Transit, included coverage, tracking and route restrictions must be verified by the operator for the approved service list. Currency is supplied by the operator because V1 rates do not identify it.') }}</p>
        </x-card>
        <x-report.params-form :action="route('reports.rate-shopping.store')" submit-label="Capture current rates" :columns="3" :fields="[
            'order_number' => ['label' => 'ShipStation order number', 'value' => $prefill, 'required' => true],
            'warehouse_id' => ['label' => 'Origin warehouse ID', 'type' => 'number', 'min' => 1, 'required' => true],
            'carrier_code' => ['label' => 'Carrier code', 'required' => true],
            'currency' => ['label' => 'Account currency (operator-confirmed)', 'placeholder' => 'USD', 'maxlength' => 3, 'required' => true],
            'package_code' => ['label' => 'Package code', 'value' => 'package', 'required' => true],
            'confirmation' => ['label' => 'Delivery confirmation', 'value' => 'delivery', 'list' => 'rate-confirmations', 'required' => true],
            'weight_value' => ['label' => 'Parcel weight', 'type' => 'number', 'min' => 0.01, 'step' => 'any', 'required' => true],
            'weight_unit' => ['label' => 'Weight unit', 'value' => 'grams', 'list' => 'rate-weight-units', 'required' => true],
            'dimension_unit' => ['label' => 'Dimension unit', 'value' => 'centimeters', 'list' => 'rate-dimension-units', 'required' => true],
            'length' => ['label' => 'Length', 'type' => 'number', 'min' => 0.01, 'step' => 'any', 'required' => true],
            'width' => ['label' => 'Width', 'type' => 'number', 'min' => 0.01, 'step' => 'any', 'required' => true],
            'height' => ['label' => 'Height', 'type' => 'number', 'min' => 0.01, 'step' => 'any', 'required' => true],
            'allowed_services' => ['label' => 'Approved service codes (comma separated)', 'required' => true],
            'max_transit_days' => ['label' => 'Required maximum transit days', 'type' => 'number', 'value' => 5, 'min' => 1, 'max' => 30, 'required' => true],
            'minimum_coverage' => ['label' => 'Required included coverage (account currency)', 'type' => 'number', 'value' => 0, 'min' => 0, 'step' => 'any', 'required' => true],
        ]">
            <datalist id="rate-confirmations">@foreach (['none', 'delivery', 'signature', 'adult_signature', 'direct_signature'] as $value)<option value="{{ $value }}">@endforeach</datalist>
            <datalist id="rate-weight-units">@foreach (['grams', 'ounces', 'pounds'] as $value)<option value="{{ $value }}">@endforeach</datalist>
            <datalist id="rate-dimension-units"><option value="centimeters"><option value="inches"></datalist>
            <div class="flex flex-col gap-3">
                <label class="flex items-center gap-2"><input type="hidden" name="residential" value="0"><input type="checkbox" name="residential" value="1" @checked(old('residential'))>{{ __('Residential destination') }}</label>
                <label class="flex items-center gap-2"><input type="hidden" name="tracking_required" value="0"><input type="checkbox" name="tracking_required" value="1" @checked(old('tracking_required', true))>{{ __('Tracking required') }}</label>
                <label class="flex items-start gap-2"><input type="checkbox" name="equivalence_confirmed" value="1" required>{{ __('I verified that every approved service meets the stated deadline, tracking, included coverage and route restrictions. No additional insurance or DDP is required.') }}</label>
                @error('equivalence_confirmed')<p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>@enderror
                @error('allowed_services.*')<p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>@enderror
            </div>
        </x-report.params-form>
        <x-data-table :headers="['Order', 'Basis', 'Status', 'Captured', 'Action']">
            @forelse ($snapshots as $snapshot)
                <tr><td class="px-4 py-3">{{ $snapshot->order_number }}</td><td class="px-4 py-3">{{ $snapshot->mode === 'recorded_decision' ? __('Recorded quote decision') : __('Current-tariff simulation') }}</td><td class="px-4 py-3">{{ __($snapshot->status) }}</td><td class="px-4 py-3">{{ \App\Support\UiFormat::date($snapshot->quoted_at, true) }}</td><td class="px-4 py-3"><x-button size="sm" variant="ghost" :href="route('reports.rate-shopping.result', $snapshot->id)">{{ __('Review') }}</x-button></td></tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">{{ __('No captured rate snapshots.') }}</td></tr>
            @endforelse
        </x-data-table>
        {{ $snapshots->links() }}
    </div>
@endsection
