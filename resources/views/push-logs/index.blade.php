@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Push Log" subtitle="History of push attempts to ShipStation for this store." />

        <x-card>
            <form class="flex flex-col gap-3 sm:flex-row sm:items-end" method="GET">
                <div class="min-w-0 flex-1">
                    <label class="text-sm font-medium" for="push-search">{{ __('Order or Shopify ID') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="push-search" name="q" type="search" value="{{ $q }}">
                </div>
                <x-button type="submit">{{ __('Search') }}</x-button>
            </form>
        </x-card>

        <x-data-table :headers="['Order', 'Shopify ID', 'ShipStation ID', 'Status', 'Error', 'Attempted at', '']">
            @forelse ($pushes as $push)
                <tr>
                    <td class="px-4 py-3">{{ $push->order_number }}</td>
                    <td class="px-4 py-3">
                        @if ($push->shopify_id !== '')
                        <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $push->shopify_id }}" target="_blank" rel="noopener noreferrer">{{ $push->shopify_id }}</a>
                        @else — @endif
                    </td>
                    <td class="px-4 py-3">{{ $push->shipstation_order_id ?: '-' }}</td>
                    <td class="px-4 py-3"><x-badge :tone="$push->status === 'failed' ? 'danger' : 'ok'">{{ ucfirst($push->status) }}</x-badge></td>
                    <td class="px-4 py-3">{{ $push->error_category ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $push->pushed_at->toDateTimeString() }}</td>
                    <td class="px-4 py-3">@if ($push->status === 'success')
                        <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="https://app.shipstation.com/#!/orders/all-orders-search-result?quickSearch={{ urlencode(ltrim($push->order_number, '#')) }}" target="_blank" rel="noopener noreferrer">{{ __('View in SS') }}</a>
                    @else — @endif</td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="7">{{ __('No push attempts yet.') }}</td></tr>
            @endforelse
        </x-data-table>

        {{ $pushes->links() }}
    </div>
@endsection
