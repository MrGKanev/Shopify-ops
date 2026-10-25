@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Settings" :title="__('SKU aliases')" :subtitle="__('Combine explicitly mapped SKU codes in Fulfilled Items, Returned Items and Inventory Forecast.')" />
        <x-alert tone="warn">{{ __('Mappings are case-sensitive and one-to-one in units. Do not map bundles or multipacks to individual units. Inventory forecasts require one unambiguous master variant; conflicting stock is excluded and flagged. Saved reports keep their original results.') }}</x-alert>
        @if ($errors->any())<x-alert tone="error">{{ $errors->first() }}</x-alert>@endif
        <form method="POST" action="{{ route('admin.sku-aliases.update') }}" class="flex flex-col gap-6">
            @csrf @method('PUT')
            <x-card>
                <input type="hidden" name="enabled" value="0">
                <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $store->sku_aliases_enabled))> {{ __('Enable SKU aliases for new reports') }}</label>
            </x-card>
            @php
                $submitted = old('aliases');
                $mappings = is_array($submitted) ? array_slice(array_values(array_filter($submitted, is_array(...))), 0, 205) : [...($store->sku_aliases ?? []), ...array_fill(0, 5, ['alias' => '', 'master' => ''])];
            @endphp
            <x-data-table :headers="['Alias SKU', 'Master SKU']" :rows="$mappings">
                @foreach ($mappings as $index => $mapping)
                    <tr>
                        <td class="px-4 py-3"><input class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" aria-label="{{ __('Alias SKU') }} {{ $index + 1 }}" name="aliases[{{ $index }}][alias]" maxlength="255" value="{{ is_string($mapping['alias'] ?? null) ? $mapping['alias'] : '' }}"></td>
                        <td class="px-4 py-3"><input class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" aria-label="{{ __('Master SKU') }} {{ $index + 1 }}" name="aliases[{{ $index }}][master]" maxlength="255" value="{{ is_string($mapping['master'] ?? null) ? $mapping['master'] : '' }}"></td>
                    </tr>
                @endforeach
            </x-data-table>
            <p>{{ __('Clear both fields to remove a mapping. Save to get five additional empty rows. Maximum 200 mappings per store.') }}</p>
            <x-button type="submit">{{ __('Save') }}</x-button>
        </form>
    </div>
@endsection
