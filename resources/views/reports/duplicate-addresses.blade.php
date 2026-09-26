@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Risk report" title="Duplicate Shipping Addresses" subtitle="Find different customer emails shipping to the same normalized address." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.duplicate-addresses.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="text-sm text-red-600">{{ __($message) }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end">
                <x-button type="submit">{{ __('Run report') }}</x-button>
            </div>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} shared addresses</h2>

                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: orders truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                <x-data-table :headers="['Address', 'Name', 'Emails', 'Orders', 'Details']">
                    @forelse ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3">{{ $row['address_line'] }}</td>
                            <td class="px-4 py-3">{{ $row['address_name'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ implode(', ', $row['emails']) }}</td>
                            <td class="px-4 py-3">{{ $row['order_count'] }}</td>
                            <td class="px-4 py-3">
                                @foreach ($row['orders'] as $order)
                                    <div>@if ($order['shopify_id'])<a class="text-indigo-600" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $order['shopify_id'] }}">{{ $order['order_number'] }}</a>@else{{ $order['order_number'] }}@endif · {{ $order['email'] }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="5">{{ __('No duplicate shipping addresses.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
