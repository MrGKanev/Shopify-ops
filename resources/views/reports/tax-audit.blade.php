@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Compliance report" title="Tax Audit" subtitle="Review paid, non-exempt orders above the minimum where Shopify charged no tax." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.tax-audit.store') }}">
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
                <label class="text-sm font-medium" for="minimum">{{ __('Minimum total') }}</label>
                <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="minimum" name="minimum" type="number" min="0" step="1" value="{{ old('minimum', $minimum) }}">
                @error('minimum')
                    <p class="text-sm text-red-600">{{ __($message) }}</p>
                @enderror
            </div>
            <div class="flex items-end"><x-button type="submit">{{ __('Run report') }}</x-button></div>
        </form>

        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif
        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} zero-tax orders</h2>
                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                <x-data-table :headers="['Order', 'Date', 'Email', 'Total']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-semibold">@if ($row['id'])<a class="text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $row['id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['number'] }}</a>@else{{ $row['number'] }}@endif</td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['total'], 2) }} {{ $row['currency'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="4">{{ __('No qualifying zero-tax orders found.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
