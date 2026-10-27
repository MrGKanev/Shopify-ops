@extends('layouts.app')

@section('content')
    <style @cspNonce>@media print { header, aside, .print-hidden { display: none !important; } main { width: 100%; } body { background: white; color: black; } }</style>
    <div class="flex flex-col gap-6">
        <div class="print-hidden"><x-page-header title="{{ __('Pick List') }}" subtitle="{{ __('Combine the queued orders into one pick list with per-order allocations.') }}" /></div>
        @error('pick_list')<x-alert tone="error" class="print-hidden">{{ $message }}</x-alert>@enderror

        <x-card class="print-hidden">
            <form class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end" method="GET" action="{{ route('print-queue.pick-list') }}">
                <div>
                    <label class="text-sm font-medium" for="template">{{ __('Template') }}</label>
                    <select class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="template" name="template">
                        <option value="">{{ __('Default columns') }}</option>
                        @foreach ($templates as $saved)
                            <option value="{{ $saved->id }}" @selected($template->id === $saved->id)>{{ $saved->name }}</option>
                        @endforeach
                    </select>
                </div>
                <input type="hidden" name="generate" value="1">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="customer_context" value="1" @checked(request()->boolean('customer_context'))> {{ __('Add customer context') }}</label>
                <x-button type="submit">{{ __('Generate for :count queued orders', ['count' => $queued]) }}</x-button>
                @if ($queued > 0)
                    <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('print-queue.pick-list.export', array_filter(['template' => $template->id])) }}">{{ __('Download CSV') }}</a>
                @endif
            </form>
        </x-card>

        @if ($failure === 'configuration')<x-alert tone="warn" class="print-hidden">{{ __('ShipStation is not configured completely for this store.') }}</x-alert>@endif
        @if ($failure === 'lookup')<x-alert tone="error" class="print-hidden">{{ __('The pick list could not be loaded. Try again.') }}</x-alert>@endif
        @if ($queued === 0)<x-empty-state class="print-hidden" title="{{ __('Print queue is empty') }}" />@endif

        @if ($queued > 0)
            <form class="print-hidden" method="POST" action="{{ route('print-queue.pick-list.snapshots.store', array_filter(['template' => $template->id])) }}">@csrf<x-button type="submit" variant="ghost">{{ __('Save snapshot of the current pick list') }}</x-button></form>
        @endif

        @if ($result)
            @if ($result['missing'] !== [])<x-alert tone="warn" class="print-hidden">{{ __('Not found in ShipStation, excluded from totals:') }} {{ implode(', ', $result['missing']) }}</x-alert>@endif
            @if ($result['ambiguous'] !== [])<x-alert tone="warn" class="print-hidden">{{ __('More than one exact match, excluded from totals:') }} {{ implode(', ', $result['ambiguous']) }}</x-alert>@endif
            @foreach ($result['warnings'] as $warning)<x-alert tone="warn" class="print-hidden">{{ $warning }}</x-alert>@endforeach

            @include('print-queue.partials.pick-list-document', ['result' => $result, 'columns' => $template->columns, 'size' => $template->size, 'columnLabels' => $columnLabels, 'customerContexts' => $customerContexts])
        @endif

        @if ($snapshots->isNotEmpty())
            <x-card class="print-hidden">
                <h3 class="text-lg font-semibold">{{ __('Saved snapshots') }}</h3>
                <ul class="mt-4 flex flex-col gap-2">
                    @foreach ($snapshots as $saved)
                        <li class="flex flex-wrap items-center gap-3">
                            <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('print-queue.pick-list.snapshots.show', $saved->id) }}">{{ $saved->created_at->format('Y-m-d H:i') }}@if ($saved->template_name) · {{ $saved->template_name }}@endif</a>
                            <span class="text-sm text-slate-500">{{ $saved->payload['orderCount'] }} {{ __('orders') }} · {{ $saved->payload['totalQuantity'] }} {{ __('units') }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        <x-card class="print-hidden">
            <h3 class="text-lg font-semibold">{{ __('Save template') }}</h3>
            <form class="mt-4 grid gap-4 sm:grid-cols-2" method="POST" action="{{ route('print-queue.pick-list.templates.store') }}">
                @csrf
                <div class="sm:col-span-2">
                    <label class="text-sm font-medium" for="template_name">{{ __('Name') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="template_name" name="name" maxlength="80" required value="{{ old('name', $template->name) }}">
                    @error('name')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>@enderror
                </div>
                <fieldset class="sm:col-span-2">
                    <legend class="text-sm font-medium">{{ __('Columns (in order)') }}</legend>
                    <div class="mt-2 flex flex-wrap gap-4">
                        @foreach (array_unique(array_merge($template->columns, array_keys($columnLabels))) as $column)
                            <label class="flex items-center gap-2"><input type="checkbox" name="columns[]" value="{{ $column }}" @checked(in_array($column, old('columns', $template->columns), true))> {{ __($columnLabels[$column]) }}</label>
                        @endforeach
                    </div>
                    @error('columns')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>@enderror
                </fieldset>
                <div>
                    <label class="text-sm font-medium" for="sort">{{ __('Sort by') }}</label>
                    <select class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="sort" name="sort"><option value="location" @selected($template->sort === 'location')>{{ __('Bin location (natural order)') }}</option><option value="sku" @selected($template->sort === 'sku')>{{ __('SKU') }}</option></select>
                </div>
                <div>
                    <label class="text-sm font-medium" for="size">{{ __('Text and image size') }}</label>
                    <select class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="size" name="size"><option value="normal" @selected($template->size === 'normal')>{{ __('Normal') }}</option><option value="large" @selected($template->size === 'large')>{{ __('Large') }}</option><option value="xlarge" @selected($template->size === 'xlarge')>{{ __('Extra large') }}</option></select>
                </div>
                <div class="flex gap-3"><x-button type="submit">{{ __('Save template') }}</x-button></div>
            </form>
            @if ($template->exists)
                <form class="mt-3" method="POST" action="{{ route('print-queue.pick-list.templates.destroy', $template->id) }}">@csrf @method('DELETE')<x-button type="submit" variant="danger">{{ __('Delete template') }}</x-button></form>
            @endif
        </x-card>
    </div>
@endsection
