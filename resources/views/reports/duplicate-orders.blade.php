@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Risk report" title="Duplicate Detector" subtitle="Find order pairs with the same customer email and total placed no more than 10 minutes apart." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.duplicate-orders.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} scanned · {{ count($result->rows) }} duplicate pairs</x-slot:heading>

                <x-data-table :headers="['Order A', 'Order B', 'Email', 'Total', 'Gap', 'Statuses']" :rows="$result->rows" empty="No orders shared the same email and total within 10 minutes.">
                    @foreach ($result->rows as $pair)
                        @php($first = $pair['first'])
                        @php($second = $pair['second'])
                        <tr class="align-top">
                            <td class="px-4 py-3"><x-report.shopify-link :id="$first['id']">{{ $first['name'] }}</x-report.shopify-link><br><span class="text-xs text-slate-500">{{ substr((string) $first['created_at'], 0, 16) }}</span></td>
                            <td class="px-4 py-3"><x-report.shopify-link :id="$second['id']">{{ $second['name'] }}</x-report.shopify-link><br><span class="text-xs text-slate-500">{{ substr((string) $second['created_at'], 0, 16) }}</span></td>
                            <td class="px-4 py-3">{{ $first['email'] }}</td>
                            <td class="px-4 py-3">{{ $first['currency'] ?? '' }} {{ number_format((float) $first['total_price'], 2) }}</td>
                            <td class="px-4 py-3">{{ intdiv($pair['gap_seconds'], 60) }}m {{ $pair['gap_seconds'] % 60 }}s</td>
                            <td class="px-4 py-3">{{ $first['financial_status'] ?: '-' }} / {{ $second['financial_status'] ?: '-' }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
