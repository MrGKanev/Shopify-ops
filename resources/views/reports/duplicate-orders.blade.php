@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Risk report" title="Duplicate Detector" subtitle="Find order pairs with the same customer email and total placed no more than 10 minutes apart." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.duplicate-orders.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end">
                <x-button type="submit">Run report</x-button>
            </div>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">Shopify credentials are incomplete for the active store.</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">The report could not be completed. Check Shopify and try again.</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->pairs) }} duplicate pairs</h2>

                @if ($result->truncated)
                    <x-alert tone="warn">Results are incomplete: orders truncated after {{ $result->pages }} pages.</x-alert>
                @endif

                <x-data-table :headers="['Order A', 'Order B', 'Email', 'Total', 'Gap', 'Statuses']">
                    @forelse ($result->pairs as $pair)
                        @php($first = $pair['first'])
                        @php($second = $pair['second'])
                        <tr class="align-top">
                            <td class="px-4 py-3">@if ($first['id'])<a class="text-indigo-600" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $first['id'] }}" target="_blank" rel="noopener noreferrer">{{ $first['name'] }}</a>@else{{ $first['name'] }}@endif<br><span class="text-xs text-slate-500">{{ substr((string) $first['created_at'], 0, 16) }}</span></td>
                            <td class="px-4 py-3">@if ($second['id'])<a class="text-indigo-600" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $second['id'] }}" target="_blank" rel="noopener noreferrer">{{ $second['name'] }}</a>@else{{ $second['name'] }}@endif<br><span class="text-xs text-slate-500">{{ substr((string) $second['created_at'], 0, 16) }}</span></td>
                            <td class="px-4 py-3">{{ $first['email'] }}</td>
                            <td class="px-4 py-3">{{ $first['currency'] ?? '' }} {{ number_format((float) $first['total_price'], 2) }}</td>
                            <td class="px-4 py-3">{{ intdiv($pair['gap_seconds'], 60) }}m {{ $pair['gap_seconds'] % 60 }}s</td>
                            <td class="px-4 py-3">{{ $first['financial_status'] ?: '-' }} / {{ $second['financial_status'] ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">No orders shared the same email and total within 10 minutes.</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
