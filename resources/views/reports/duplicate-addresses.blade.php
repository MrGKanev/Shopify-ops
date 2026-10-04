@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Risk report" title="{{ __('Duplicate Shipping Addresses') }}" subtitle="Find different customer emails shipping to the same normalized address." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.duplicate-addresses.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} {{ __('scanned ·') }} {{ \App\Support\UiFormat::count(count($result->rows), 'shared addresses') }}</x-slot:heading>

                <x-data-table :headers="['Address', 'Name', 'Emails', 'Orders', 'Details']" :rows="$result->rows" empty="No duplicate shipping addresses.">
                    @foreach ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3">{{ $row['address_line'] }}</td>
                            <td class="px-4 py-3">{{ $row['address_name'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ implode(', ', $row['emails']) }}</td>
                            <td class="px-4 py-3">{{ $row['order_count'] }}</td>
                            <td class="px-4 py-3">
                                @foreach ($row['orders'] as $order)
                                    <div><x-report.shopify-link :id="$order['shopify_id']">{{ $order['order_number'] }}</x-report.shopify-link> · {{ $order['email'] }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
