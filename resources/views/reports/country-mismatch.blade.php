@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Audit report" title="Billing ≠ shipping country" subtitle="Manual review queue for paid orders whose billing and shipping countries differ." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.country-mismatch.store') }}">
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

        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} mismatches</h2>
                @if ($result->skippedMissingCountry)
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $result->skippedMissingCountry }} skipped because an ISO country code was missing.</p>
                @endif
                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results were truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                @forelse ($result->rows as $row)
                    <x-card>
                        <div class="flex justify-between gap-4">
                            <div>
                                @if ($row['id'])
                                    <a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $row['id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['number'] }}</a>
                                @else
                                    <strong>{{ $row['number'] }}</strong>
                                @endif
                                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $row['created_at'] }} · {{ $row['email'] ?: 'No email' }}</p>
                            </div>
                            <strong>{{ number_format($row['total'], 2) }} {{ $row['currency'] }}</strong>
                        </div>
                        <div class="mt-3 flex gap-3">
                            <span>Billing: {{ $row['billing_country'] }}@if ($row['billing_name']) · {{ $row['billing_name'] }}@endif</span>
                            <span>Shipping: {{ $row['shipping_country'] }}</span>
                            <span>{{ $row['financial'] }} · {{ $row['fulfillment'] ?: 'unfulfilled' }}</span>
                        </div>
                        <a class="mt-3 inline-flex text-sm text-indigo-600 dark:text-indigo-400" href="{{ route('orders.spot-check', ['prefill' => ltrim($row['number'], '#')]) }}">{{ __('Open in spot-check') }}</a>
                    </x-card>
                @empty
                    <x-empty-state icon="🌍" title="No mismatches">No billing and shipping country mismatches were found.</x-empty-state>
                @endforelse
            </section>
        @endif
    </div>
@endsection
