@extends('layouts.app')

@section('content')
    @php
        $stateLabels = ['present' => __('Value present'), 'missing' => __('Value missing'), 'unreadable' => __('Value unreadable')];
        $stateTones = ['present' => 'ok', 'missing' => 'warn', 'unreadable' => 'danger'];
        $conditionLabels = ['missing' => __('Value missing'), 'unreadable' => __('Value unreadable'), 'present' => __('Value present'), 'contains' => __('Contains text')];
        $input = 'mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950';
    @endphp
    <div class="flex flex-col gap-6">
        <x-page-header title="{{ __('Operational fields') }}" subtitle="{{ __('Give metafields and ShipStation custom fields one name per store, and see them next to the queued orders and in the CSV. Read-only; nothing is written to Shopify or ShipStation.') }}" />
        @error('operational_fields')<x-alert tone="error">{{ $message }}</x-alert>@enderror

        <x-card>
            <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_12rem_1fr_auto_auto] lg:items-end" method="POST" action="{{ route('operational-fields.store') }}">
                @csrf
                <div>
                    <label class="text-sm font-medium" for="alias">{{ __('Name') }}</label>
                    <input class="{{ $input }}" id="alias" name="alias" maxlength="80" required placeholder="PO reference" value="{{ old('alias') }}">
                    @error('alias')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="text-sm font-medium" for="source_type">{{ __('Source') }}</label>
                    <select class="{{ $input }}" id="source_type" name="source_type">
                        <option value="metafield" @selected(old('source_type') === 'metafield')>{{ __('Shopify order metafield') }}</option>
                        <option value="ss_custom" @selected(old('source_type') === 'ss_custom')>{{ __('ShipStation custom field') }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium" for="source">{{ __('Identifier') }}</label>
                    <input class="{{ $input }}" id="source" name="source" maxlength="255" required placeholder="custom.po_number / customField1" value="{{ old('source') }}">
                    @error('source')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <label class="flex items-center gap-2 pb-2"><input type="checkbox" name="required" value="1" @checked(old('required'))> {{ __('Required') }}</label>
                <x-button type="submit">{{ __('Save field') }}</x-button>
            </form>
        </x-card>

        <x-data-table :headers="['Name', 'Source', 'Identifier', 'Required', 'Actions']">
            @forelse ($fields as $field)
                <tr>
                    <td class="px-4 py-3 font-semibold">{{ $field->alias }}</td>
                    <td class="px-4 py-3">{{ $field->source_type === 'metafield' ? __('Shopify order metafield') : __('ShipStation custom field') }}</td>
                    <td class="px-4 py-3 font-mono text-sm">{{ $field->source }}</td>
                    <td class="px-4 py-3">{{ $field->required ? __('Yes') : __('No') }}</td>
                    <td class="px-4 py-3"><form method="POST" action="{{ route('operational-fields.destroy', $field->id) }}">@csrf @method('DELETE')<button class="font-medium text-red-600 hover:underline dark:text-red-400" type="submit">{{ __('Delete') }}</button></form></td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="5">{{ __('No operational fields yet.') }}</td></tr>
            @endforelse
        </x-data-table>

        @if ($fields->isNotEmpty())
            <x-card>
                <h3 class="text-lg font-semibold">{{ __('Saved filters') }}</h3>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    @forelse ($filters as $filter)
                        <span class="inline-flex items-center gap-2 rounded-full border border-slate-300 px-3 py-1 dark:border-slate-700 {{ $savedFilter?->id === $filter->id ? 'bg-indigo-50 dark:bg-indigo-950' : '' }}">
                            <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('operational-fields.index', ['filter' => $filter->id, 'generate' => 1]) }}">{{ $filter->name }}</a>
                            <span class="text-xs text-slate-500">{{ $filter->field->alias }}: {{ $conditionLabels[$filter->condition] }}@if ($filter->condition === 'contains') “{{ $filter->text }}”@endif</span>
                            <form method="POST" action="{{ route('operational-fields.filters.destroy', $filter->id) }}">@csrf @method('DELETE')<button class="text-sm text-red-600 hover:underline dark:text-red-400" type="submit" aria-label="{{ __('Delete') }}">×</button></form>
                        </span>
                    @empty
                        <span class="text-sm text-slate-500">{{ __('No saved filters yet.') }}</span>
                    @endforelse
                </div>
                <form class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_1fr_auto] lg:items-end" method="POST" action="{{ route('operational-fields.filters.store') }}">
                    @csrf
                    <div>
                        <label class="text-sm font-medium" for="filter_name">{{ __('Filter name') }}</label>
                        <input class="{{ $input }}" id="filter_name" name="name" maxlength="80" required value="{{ old('name', $savedFilter?->name) }}">
                        @error('name')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="text-sm font-medium" for="filter_field">{{ __('Field') }}</label>
                        <select class="{{ $input }}" id="filter_field" name="field">
                            @foreach ($fields as $field)<option value="{{ $field->id }}" @selected((int) old('field', $savedFilter?->operational_field_id) === $field->id)>{{ $field->alias }}</option>@endforeach
                        </select>
                        @error('field')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="text-sm font-medium" for="filter_condition">{{ __('Condition') }}</label>
                        <select class="{{ $input }}" id="filter_condition" name="condition">
                            @foreach ($conditionLabels as $value => $label)<option value="{{ $value }}" @selected(old('condition', $savedFilter?->condition) === $value)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium" for="filter_text">{{ __('Text') }}</label>
                        <input class="{{ $input }}" id="filter_text" name="text" maxlength="120" value="{{ old('text', $savedFilter?->text) }}">
                        @error('text')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    </div>
                    <x-button type="submit">{{ __('Save current filter') }}</x-button>
                </form>
            </x-card>
        @endif

        <x-card>
            <form class="flex flex-wrap items-center gap-4" method="GET" action="{{ route('operational-fields.index') }}">
                <input type="hidden" name="generate" value="1">
                @if ($savedFilter)<input type="hidden" name="filter" value="{{ $savedFilter->id }}">@endif
                <x-button type="submit">{{ __('Load values for :count queued orders', ['count' => $queued]) }}</x-button>
                @if ($queued > 0 && $fields->isNotEmpty())
                    <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('operational-fields.export', $savedFilter ? ['filter' => $savedFilter->id] : []) }}">{{ __('Download CSV') }}</a>
                @endif
            </form>
        </x-card>

        @if ($failure === 'configuration')<x-alert tone="warn">{{ __('ShipStation is not configured completely for this store.') }}</x-alert>@endif
        @if ($failure === 'lookup')<x-alert tone="error">{{ __('The operational fields could not be loaded. Try again.') }}</x-alert>@endif

        @if ($result)
            @if ($savedFilter)<x-alert tone="ok">{{ __('Filter “:name” shows :shown of :total loaded orders.', ['name' => $savedFilter->name, 'shown' => count($result['rows']), 'total' => $result['total']]) }}</x-alert>@endif
            @if ($result['missing'] !== [])<x-alert tone="warn">{{ __('Not found in ShipStation:') }} {{ implode(', ', $result['missing']) }}</x-alert>@endif
            @if ($result['ambiguous'] !== [])<x-alert tone="warn">{{ __('More than one exact match, excluded:') }} {{ implode(', ', $result['ambiguous']) }}</x-alert>@endif
            @if ($result['findings'] !== [])
                <x-alert tone="warn">
                    <strong>{{ __('Required values are not usable') }}</strong>
                    @foreach ($result['findings'] as $finding)<div>{{ $finding['order'] }} — {{ $finding['alias'] }}: {{ $stateLabels[$finding['state']] }}</div>@endforeach
                </x-alert>
            @endif
            <x-data-table :headers="array_merge(['Order'], array_column($result['fields'], 'alias'))">
                @forelse ($result['rows'] as $row)
                    <tr>
                        <td class="px-4 py-3 font-semibold">{{ $row['order'] }}</td>
                        @foreach ($row['fields'] as $field)
                            <td class="px-4 py-3">@if ($field['state'] === 'present'){{ $field['value'] }}@else<x-badge :tone="$stateTones[$field['state']]">{{ $stateLabels[$field['state']] }}</x-badge>@endif</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td class="px-4 py-8 text-center text-slate-500" colspan="{{ count($result['fields']) + 1 }}">{{ __('No orders loaded.') }}</td></tr>
                @endforelse
            </x-data-table>
        @endif
    </div>
@endsection
