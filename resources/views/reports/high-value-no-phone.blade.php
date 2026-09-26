@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Audit report" title="High-value orders without phone" subtitle="Paid, unfulfilled orders whose shipping address has no phone number." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.high-value-no-phone.store') }}">
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
            <div>
                <label class="text-sm font-medium" for="minimum">{{ __('Minimum value') }}</label>
                <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="minimum" name="minimum" type="number" min="0" step="0.01" value="{{ old('minimum', $minimum) }}">
                @error('minimum')
                    <p class="text-sm text-red-600">{{ __($message) }}</p>
                @enderror
            </div>
            <div>
                <label class="text-sm font-medium" for="currency">{{ __('Currency') }}</label>
                <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 uppercase dark:border-slate-700 dark:bg-slate-950" id="currency" name="currency" maxlength="3" list="currency-options" value="{{ old('currency', $currency) }}">
                <datalist id="currency-options"><option value="ALL">{{ __('All currencies') }}</option></datalist>
                @error('currency')
                    <p class="text-sm text-red-600">{{ __($message) }}</p>
                @enderror
            </div>
            <div class="flex items-end">
                <x-button type="submit">{{ __('Run report') }}</x-button>
            </div>
        </form>

        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check the Shopify integration and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <div class="flex flex-wrap gap-3">
                    <h2 class="text-2xl font-bold">{{ __('Results') }}</h2>
                    <span>{{ $result->scanned }} scanned · {{ count($result->rows) }} issues</span>
                </div>
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
                                <p class="mt-2 text-sm">{{ $row['recipient'] }}@if ($row['recipient'] && $row['address']), @endif{{ $row['address'] }}</p>
                            </div>
                            <strong>{{ number_format($row['total'], 2) }} {{ $row['currency'] }}</strong>
                        </div>
                        <a class="mt-3 inline-flex text-sm text-indigo-600 dark:text-indigo-400" href="{{ route('orders.spot-check', ['prefill' => ltrim($row['number'], '#')]) }}">{{ __('Open in spot-check') }}</a>
                    </x-card>
                @empty
                    <x-empty-state icon="✓">No high-value orders without a shipping phone were found.</x-empty-state>
                @endforelse
            </section>
        @endif
    </div>
@endsection
