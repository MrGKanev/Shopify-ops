@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Risk report" title="Discount Abuse" subtitle="Find discount codes used by multiple customer emails at the same shipping address." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.discount-abuse.store') }}">
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
            <div>
                <label class="text-sm font-medium" for="minimum_emails">Minimum distinct emails</label>
                <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="minimum_emails" min="2" max="100" name="minimum_emails" type="number" value="{{ old('minimum_emails', $minimumEmails) }}">
                @error('minimum_emails')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
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
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} suspicious clusters</h2>

                @if ($result->truncated)
                    <x-alert tone="warn">Results are incomplete: orders truncated after {{ $result->pages }} pages.</x-alert>
                @endif

                <x-data-table :headers="['Code', 'Address', 'Emails', 'Orders', 'Total', 'Details']">
                    @forelse ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-semibold">{{ $row['code'] }}</td>
                            <td class="px-4 py-3">{{ $row['address_name'] ?: '—' }}<br><span class="text-slate-500">{{ $row['address_line'] }}</span></td>
                            <td class="px-4 py-3">{{ $row['email_count'] }}<br><span class="text-slate-500">{{ implode(', ', array_slice($row['emails'], 0, 4)) }}</span></td>
                            <td class="px-4 py-3">{{ $row['order_count'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['total'], 2) }}</td>
                            <td class="px-4 py-3">
                                <details>
                                    <summary>View orders</summary>
                                    <ul class="mt-2">
                                        @foreach ($row['orders'] as $order)
                                            <li>@if ($order['id'])<a class="text-indigo-600" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $order['id'] }}" target="_blank" rel="noopener noreferrer">{{ $order['number'] }}</a>@else{{ $order['number'] }}@endif · {{ $order['email'] }} · {{ number_format($order['total'], 2) }} {{ $order['currency'] }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">No discount clusters met the configured threshold.</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
