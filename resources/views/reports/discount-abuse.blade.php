@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Risk report" title="{{ __('Discount Abuse') }}" subtitle="Find discount codes used by multiple customer emails at the same shipping address." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form
                :action="route('reports.discount-abuse.store')"
                :start-date="$startDate"
                :end-date="$endDate"
                :fields="['minimum_emails' => ['label' => 'Minimum distinct emails', 'type' => 'number', 'value' => $minimumEmails, 'min' => 2, 'max' => 100]]"
            />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} {{ __('scanned ·') }} {{ \App\Support\UiFormat::count(count($result->rows), 'suspicious clusters') }}</x-slot:heading>

                <x-data-table :headers="['Code', 'Address', 'Emails', 'Orders', 'Total', 'Details']" :rows="$result->rows" empty="No discount clusters met the configured threshold.">
                    @foreach ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-semibold">{{ $row['code'] }}</td>
                            <td class="px-4 py-3">{{ $row['address_name'] ?: '—' }}<br><span class="text-slate-500">{{ $row['address_line'] }}</span></td>
                            <td class="px-4 py-3">{{ $row['email_count'] }}<br><span class="text-slate-500">{{ implode(', ', array_slice($row['emails'], 0, 4)) }}</span></td>
                            <td class="px-4 py-3">{{ $row['order_count'] }}</td>
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::number($row['total'], 2) }}</td>
                            <td class="px-4 py-3">
                                <details>
                                    <summary>{{ __('View orders') }}</summary>
                                    <ul class="mt-2">
                                        @foreach ($row['orders'] as $order)
                                            <li><x-report.shopify-link :id="$order['id']">{{ $order['number'] }}</x-report.shopify-link> · {{ $order['email'] }} · {{ \App\Support\UiFormat::number($order['total'], 2) }} {{ $order['currency'] }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
