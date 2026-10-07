@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Review report" title="{{ __('Duplicate Shipping Addresses') }}" subtitle="Review different customer emails sharing a shipping address, including apartment or unit." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.duplicate-addresses.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        <x-alert>{{ __('Shared addresses may belong to families, offices or forwarding services. Different names or emails are not proof of fraud. Warning only; orders are not blocked.') }}</x-alert>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} {{ __('scanned ·') }} {{ \App\Support\UiFormat::count(count($result->rows), 'shared addresses') }}</x-slot:heading>

                <x-data-table :headers="['Address', 'Name', 'Emails', 'Orders', 'Details']" :rows="$result->rows" empty="No duplicate shipping addresses.">
                    @foreach ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3">{{ $row['address_line'] }}</td>
                            <td class="px-4 py-3">{{ implode(', ', $row['names'] ?? [$row['address_name']]) ?: '—' }}</td>
                            <td class="px-4 py-3">{{ implode(', ', $row['emails']) }}
                                @if ($row['review_context']['shared_valid_phone'] ?? false)
                                    <div>{{ __('Some orders share a valid phone number; this can be a shared contact.') }}</div>
                                @endif
                                @if ($row['review_context']['company_present'] ?? false)
                                    <div>{{ __('Company supplied; review whether this is a shared workplace or forwarding address.') }}</div>
                                @endif
                                @if (!empty($row['review_context']['address_quality_codes']))
                                    <div>{{ __('Address quality needs review in the existing Address Check report.') }}</div>
                                @endif</td>
                            <td class="px-4 py-3">{{ $row['order_count'] }}</td>
                            <td class="px-4 py-3">
                                @foreach ($row['orders'] as $order)
                                    <div><x-report.shopify-link :id="$order['shopify_id']">{{ $order['order_number'] }}</x-report.shopify-link> · {{ $order['email'] }} · {{ $order['recipient_name'] ?? '' }} @if (!empty($order['company'])) · {{ $order['company'] }} @endif</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
