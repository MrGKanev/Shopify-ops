@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Customer Lookup" subtitle="Full Shopify order history and spend summary by email." />

        <x-card>
            <form class="flex flex-col gap-4 sm:flex-row sm:items-end" method="POST" action="{{ route('customers.lookup.store') }}">
                @csrf
                <div class="min-w-0 grow">
                    <label class="text-sm font-medium" for="email">{{ __('Email') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950" id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @if ($errors->has('email')) aria-describedby="email-error" @endif autofocus>
                    @error('email')
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400" id="email-error" role="alert">{{ __($message) }}</p>
                    @enderror
                </div>
                <x-button type="submit">Look up</x-button>
            </form>
        </x-card>

        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif

        @if ($lookupFailed)
            <x-alert tone="error">{{ __('The lookup could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <x-card>
                    <h2 class="text-2xl font-bold">{{ trim(($result->customer['firstName'] ?? '').' '.($result->customer['lastName'] ?? '')) ?: $result->email }}</h2>
                    <p>{{ $result->email }}</p>
                    <p class="mt-3">{{ count($result->orders) }}{{ $result->truncated ? '+' : '' }} orders · {{ $result->currency }} {{ number_format($result->totalSpent, 2) }} spent · {{ $result->paid }} paid · {{ $result->cancelled }} cancelled</p>
                    @if ($result->tags)
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach (array_slice($result->tags, 0, 30, true) as $tag => $count)
                                <span class="rounded bg-slate-100 px-2 py-1 text-xs dark:bg-slate-800">{{ $tag }} · {{ $count }}</span>
                            @endforeach
                        </div>
                    @endif
                </x-card>

                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: orders truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                <x-data-table :headers="['Order', 'Date', 'Financial', 'Fulfillment', 'Total', 'Tags']">
                    @forelse ($result->orders as $order)
                        <tr class="{{ $order['cancelled_at'] ? 'opacity-50' : '' }}">
                            <td class="px-4 py-3">
                                @if ($order['id'])
                                    <a class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $order['id'] }}" target="_blank" rel="noopener noreferrer">{{ $order['name'] }}</a>
                                @else
                                    {{ $order['name'] }}
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ substr((string) $order['created_at'], 0, 10) }}</td>
                            <td class="px-4 py-3">{{ $order['financial_status'] ?: '-' }}</td>
                            <td class="px-4 py-3">{{ $order['fulfillment_status'] ?: '-' }}</td>
                            <td class="px-4 py-3">{{ $order['currency'] ?? '' }} {{ number_format((float) $order['total_price'], 2) }}</td>
                            <td class="px-4 py-3">{{ implode(', ', $order['tags'] ?? []) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="6">No orders found for {{ $result->email }}.</td></tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
