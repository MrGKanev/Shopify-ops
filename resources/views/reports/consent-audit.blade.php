@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Compliance report" title="Marketing Consent Audit" subtitle="Find paid orders from customers who are not actively subscribed to email marketing. SMS consent is informational." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.consent-audit.store') }}">
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
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} without active email consent</h2>
                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: orders truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif
                <x-data-table :headers="['Order', 'Date', 'Email', 'Email consent', 'SMS consent', 'Total']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">
                                @if ($row['id'])
                                    <a class="text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $row['id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['number'] }}</a>
                                @else
                                    {{ $row['number'] }}
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ str_replace('_', ' ', $row['email_consent']) }}</td>
                            <td class="px-4 py-3">{{ str_replace('_', ' ', $row['sms_consent']) }}</td>
                            <td class="px-4 py-3">{{ number_format($row['total'], 2) }} {{ $row['currency'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">{{ __('All customers in this range are actively subscribed to email marketing.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
