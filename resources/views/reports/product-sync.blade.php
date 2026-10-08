@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Catalogue report" :title="__('Product Sync & Package Weight')" subtitle="Compare product sources and review one shipment without changing external data." :configuration-error="$configurationError" credentials-message="Shopify and ShipStation credentials are required for this comparison." :report-failed="$reportFailed" failure-message="The report could not be completed. Check API health and the selected order or shipment.">
        <x-slot:form>
            @if ($errors->any())
                <x-alert tone="error"><ul class="list-disc pl-4">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-alert>
            @endif
            <x-report.params-form :action="route('reports.product-sync.store')" submit-label="Run comparison">
                <label class="flex flex-col gap-2 text-sm font-medium">{{ __('Comparison mode') }}
                    <select name="mode" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950">
                        @foreach (['catalog' => 'Product catalog', 'shipment' => 'Specific shipment'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('mode', request('mode', 'catalog')) === $value)>{{ __($label) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-2 text-sm font-medium">{{ __('Order number (shipment mode)') }}<input name="order_number" value="{{ old('order_number', request('order_number')) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950"></label>
                <label class="flex flex-col gap-2 text-sm font-medium">{{ __('Shipment ID (leave blank to choose)') }}<input name="shipment_id" type="number" min="1" value="{{ old('shipment_id', request('shipment_id')) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950"></label>
                <label class="flex flex-col gap-2 text-sm font-medium">{{ __('Package tare (blank means unknown)') }}<input name="tare" type="number" min="0" step="any" value="{{ old('tare', request('tare')) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950"></label>
                <label class="flex flex-col gap-2 text-sm font-medium">{{ __('Tare unit') }}<select name="tare_unit" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950">@foreach (['grams', 'kilograms', 'ounces', 'pounds'] as $unit)<option @selected(old('tare_unit', request('tare_unit', 'grams')) === $unit)>{{ $unit }}</option>@endforeach</select></label>
                <label class="flex flex-col gap-2 text-sm font-medium">{{ __('Optional DIM divisor (carrier-specific scenario)') }}<input name="dim_divisor" type="number" min="0.01" step="any" value="{{ old('dim_divisor', request('dim_divisor')) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950"></label>
                <label class="flex flex-col gap-2 text-sm font-medium">{{ __('DIM divisor basis') }}<select name="dim_basis" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950">@foreach (['cm_kg' => 'cm³ / kg', 'in_lb' => 'in³ / lb'] as $key => $label)<option value="{{ $key }}" @selected(old('dim_basis', request('dim_basis', 'cm_kg')) === $key)>{{ $label }}</option>@endforeach</select></label>
            </x-report.params-form>
        </x-slot:form>

        <x-alert tone="warn">{{ __('Read-only comparison. ShipStation declared weight is not measured weight. A discrepancy does not prove a wrong SKU weight or a carrier adjustment.') }}</x-alert>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('ShipStation product defaults are account-wide. A missing Shopify counterpart may belong to another store. Shopify titles are description candidates, not confirmed customs declarations.') }}</p>

        @if ($result)
            @if ($result->truncated)
                <x-alert tone="warn">{{ __('Source coverage is incomplete. Missing counterparts and package totals must not be treated as confirmed.') }}</x-alert>
            @endif
            @if ($result->meta['mode'] === 'select_shipment')
                <x-card>
                    <h2 class="text-lg font-semibold">{{ __('Choose a shipment from this order') }}</h2>
                    <div class="mt-3 flex flex-wrap gap-3">
                        @forelse ($result->meta['shipments'] as $shipment)
                            <x-button variant="ghost" :href="route('reports.product-sync.result', [...$result->params, 'shipment_id' => $shipment['id']])">#{{ $shipment['id'] }} · {{ $shipment['tracking'] ?: '—' }} · {{ $shipment['ship_date'] }}</x-button>
                        @empty
                            <x-empty-state>{{ __('No non-voided outbound label shipments were found. Externally marked shipped orders may not appear in this API.') }}</x-empty-state>
                        @endforelse
                    </div>
                </x-card>
            @endif
            @if ($result->meta['mode'] === 'shipment')
                @php($summary = $result->meta['summary'])
                <x-card>
                    <h2 class="text-lg font-semibold">{{ __('Shipment') }} #{{ $result->meta['shipment_id'] }}</h2>
                    <p class="mt-2">{{ __('Declared shipment weight') }}: {{ $summary['declared_grams'] === null ? __('Unknown') : number_format($summary['declared_grams'], 2).' g' }} · {{ __('Package tare') }}: {{ $summary['tare_grams'] === null ? __('Unknown') : number_format($summary['tare_grams'], 2).' g' }}</p>
                    <x-data-table :headers="['Weight basis', 'Goods (g)', 'Goods + tare (g)', 'Declared minus expected (g)']">
                        @foreach ($summary['comparisons'] as $source => $comparison)
                            <tr><td class="px-4 py-3">{{ __($source) }}@if($comparison['finding'])<p class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ __($comparison['finding']) }}</p>@endif</td>@foreach (['goods_grams', 'package_grams', 'delta_grams'] as $key)<td class="px-4 py-3">{{ $comparison[$key] === null ? __('Unknown') : number_format($comparison[$key], 2) }}</td>@endforeach</tr>
                        @endforeach
                    </x-data-table>
                    <p class="mt-2 text-sm">{{ __('Current product and imported order totals are estimates for the selected items, not historical measured weights.') }}</p>
                    @foreach ($summary['findings'] as $finding)<p class="mt-2 text-sm text-amber-700 dark:text-amber-300">{{ __($finding) }}</p>@endforeach
                    <p class="mt-2 text-sm">{{ __('DIM scenario') }}: {{ $summary['dim_grams'] === null ? __('Unknown') : number_format($summary['dim_grams'], 2).' g' }} · {{ __('Billable weight scenario') }}: {{ $summary['billable_scenario_grams'] === null ? __('Unknown') : number_format($summary['billable_scenario_grams'], 2).' g' }}</p>
                    <p class="mt-2 text-sm">{{ __('DIM uses your divisor and the reported dimensions. Carrier rounding, measured weight and actual billed adjustments are not verified.') }}</p>
                </x-card>
                <x-card>
                    <h2 class="text-lg font-semibold">{{ __('Current imported order customs rows') }}</h2>
                    <p class="mt-2 text-sm">{{ __('These are prepared order values. This API does not confirm that they were used on the selected historical label; defaults are not substituted automatically.') }}</p>
                    <x-data-table :headers="['Description', 'Quantity', 'HS code', 'Origin country']">
                        @forelse ($result->meta['customs_declarations'] ?? [] as $declaration)
                            <tr>@foreach (['description', 'quantity', 'harmonizedTariffCode', 'countryOfOrigin'] as $key)<td class="px-4 py-3">{{ is_scalar($declaration[$key] ?? null) ? $declaration[$key] : '—' }}</td>@endforeach</tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-3">{{ __('No prepared customs rows are available.') }}</td></tr>
                        @endforelse
                    </x-data-table>
                </x-card>
            @endif
            @if ($result->meta['mode'] !== 'select_shipment')
                <x-data-table :headers="['SKU / item', 'Source values', 'Findings']" :rows="$result->rows" empty="No physical product rows were found.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3"><div class="font-semibold">{{ $row['sku'] ?: __('Missing SKU') }}</div><div>{{ $row['title'] }}</div>@if(isset($row['quantity']))<div>{{ __('Quantity') }}: {{ $row['quantity'] }}</div>@endif @if(isset($row['side']))<div class="text-xs">{{ $row['side'] }} · Shopify: {{ $row['shopify_count'] }} · SS: {{ $row['ss_count'] }}</div>@endif</td>
                            <td class="px-4 py-3"><div class="flex flex-col gap-3">
                                @foreach ($row['sources'] as $source => $values)
                                    <div class="text-sm"><strong>{{ __($source) }}</strong><div>{{ __('Weight') }}: {{ $values['grams'] === null ? __('Unknown') : number_format($values['grams'], 2).' g' }} · HS: {{ $values['hs'] ?: '—' }} · {{ __('Origin') }}: {{ $values['origin'] ?: '—' }}</div><div>{{ $values['description'] ?: '—' }}</div></div>
                                @endforeach
                                @foreach ($row['components'] ?? [] as $component)
                                    <div class="text-xs">{{ __('Bundle component') }}: {{ data_get($component, 'productVariant.sku') }} × {{ $component['quantity'] }}</div>
                                @endforeach
                            </div></td>
                            <td class="px-4 py-3"><ul class="list-disc pl-4 text-sm">@foreach ($row['findings'] as $finding)<li>{{ __($finding) }}</li>@endforeach</ul></td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif
        @endif
    </x-report.layout>
@endsection
