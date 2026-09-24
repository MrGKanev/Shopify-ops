@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Metafields" subtitle="Browse order metafield definitions, search by value, or inspect up to 20 orders." />

        @if ($configurationError)
            <x-alert tone="warn">Shopify credentials are incomplete for the active store.</x-alert>
        @endif
        @if ($loadFailed)
            <x-alert tone="error">Metafield definitions could not be loaded.</x-alert>
        @endif
        @if ($operationFailed)
            <x-alert tone="error">The metafield operation could not be completed.</x-alert>
        @endif

        <section class="flex flex-col gap-3" aria-labelledby="definitions-heading">
            <h2 class="text-xl font-bold" id="definitions-heading">Order definitions</h2>
            <x-data-table :headers="['Namespace.key', 'Name', 'Type', 'Description']">
                @forelse ($definitions as $definition)
                    <tr>
                        <td class="px-4 py-3">{{ $definition['namespace'] }}.{{ $definition['key'] }}</td>
                        <td class="px-4 py-3">{{ $definition['name'] ?? '' }}</td>
                        <td class="px-4 py-3">{{ $definition['type']['name'] ?? '' }}</td>
                        <td class="px-4 py-3">{{ $definition['description'] ?? '' }}</td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="4">No definitions found.</td></tr>
                @endforelse
            </x-data-table>
        </section>

        <x-card>
            <form class="grid gap-4 sm:grid-cols-3" method="POST" action="{{ route('metafields.search') }}">
                @csrf
                <h2 class="text-xl font-bold sm:col-span-3">Search orders by metafield</h2>
                @foreach (['namespace' => 'Namespace', 'key' => 'Key', 'value' => 'Value (optional)'] as $field => $label)
                    <div>
                        <label class="text-sm font-medium" for="search-{{ $field }}">{{ $label }}</label>
                        <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="search-{{ $field }}" name="{{ $field }}" value="{{ old($field) }}" @error($field) aria-invalid="true" aria-describedby="search-{{ $field }}-error" @enderror>
                        @error($field)
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400" id="search-{{ $field }}-error">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
                @foreach (['start_date' => 'Start date', 'end_date' => 'End date'] as $field => $label)
                    <div>
                        <label class="text-sm font-medium" for="search-{{ $field }}">{{ $label }}</label>
                        <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="search-{{ $field }}" type="date" name="{{ $field }}" value="{{ old($field) }}" @error($field) aria-invalid="true" aria-describedby="search-{{ $field }}-error" @enderror>
                        @error($field)
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400" id="search-{{ $field }}-error">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
                <div class="flex items-end"><x-button type="submit">Search</x-button></div>
            </form>
        </x-card>

        @if ($search)
            <section class="flex flex-col gap-3" aria-labelledby="metafield-results-heading">
                <h2 class="text-xl font-bold" id="metafield-results-heading">{{ $search['scanned'] }} scanned · {{ $search['with_metafield'] }} with metafield · {{ count($search['orders']) }} matches</h2>
                @if ($search['truncated'])
                    <x-alert tone="warn">Results truncated after {{ $search['pages'] }} pages.</x-alert>
                @endif
                @if ($search['orders'] === [])
                    <x-empty-state>No matching orders found.</x-empty-state>
                @else
                    <ul class="flex flex-col gap-2">
                        @foreach ($search['orders'] as $order)
                            <li>
                                <x-card padding="p-3">
                                    <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $order['id'] }}">{{ $order['name'] }}</a> · {{ $order['metafield']['value'] }}
                                </x-card>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <x-card>
            <form class="grid gap-4" method="POST" action="{{ route('metafields.lookup') }}">
                @csrf
                <h2 class="text-xl font-bold">Inspect orders</h2>
                <div>
                    <label class="text-sm font-medium" for="lookup-orders">Order numbers</label>
                    <textarea class="mt-2 min-h-28 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="lookup-orders" name="orders" placeholder="1001, 1002" @error('orders') aria-invalid="true" aria-describedby="lookup-orders-error" @enderror>{{ old('orders') }}</textarea>
                    @error('orders')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400" id="lookup-orders-error">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="text-sm font-medium" for="lookup-filter">Optional namespace, key, or value filter</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="lookup-filter" name="filter" value="{{ old('filter') }}" @error('filter') aria-invalid="true" aria-describedby="lookup-filter-error" @enderror>
                    @error('filter')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400" id="lookup-filter-error">{{ $message }}</p>
                    @enderror
                </div>
                <div><x-button type="submit">Look up</x-button></div>
            </form>
        </x-card>

        @if ($lookup)
            <section class="flex flex-col gap-3" aria-label="Inspected orders">
                @foreach ($lookup as $row)
                    <x-card padding="p-4">
                        <h3 class="font-bold">{{ $row['name'] ?? '#'.$row['number'] }} {{ $row['found'] ? '' : '— not found' }}</h3>
                        @foreach ($row['metafields'] as $metafield)
                            <p>{{ $metafield['namespace'] }}.{{ $metafield['key'] }} = {{ $metafield['value'] }}</p>
                        @endforeach
                    </x-card>
                @endforeach
            </section>
        @endif
    </div>
@endsection
