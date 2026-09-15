@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Risk report" title="Fraud Risk Report" subtitle="Score paid orders using customer, address, payment, tag, and Shopify risk signals. Only medium and high risk orders are shown." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.fraud-risk.store') }}">
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
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} flagged</h2>

                @if ($result->truncated)
                    <x-alert tone="warn">Results are incomplete: orders truncated after {{ $result->pages }} pages.</x-alert>
                @endif

                <x-data-table :headers="['Order', 'Date', 'Email', 'Total', 'Payment', 'Risk']">
                    @forelse ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3">@if ($row['id'])<a class="text-indigo-600" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $row['id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['number'] }}</a>@else{{ $row['number'] }}@endif</td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['total'], 2) }} {{ $row['currency'] }}</td>
                            <td class="px-4 py-3">{{ str_replace('_', ' ', $row['financial']) }}</td>
                            <td class="px-4 py-3">
                                <span class="font-semibold">{{ ucfirst($row['risk']['level']) }} · {{ $row['risk']['score'] }}</span>
                                <ul class="mt-1 list-disc pl-5 text-slate-500 dark:text-slate-400">
                                    @foreach ($row['risk']['signals'] as $signal)
                                        <li>{{ $signal['label'] }} +{{ $signal['points'] }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">No medium or high risk orders were found in this range.</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
