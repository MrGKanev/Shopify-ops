@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Risk report" title="{{ __('Fraud Risk Report') }}" subtitle="Score paid orders using customer, address, payment, tag, and Shopify risk signals. Only medium and high risk orders are shown." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.fraud-risk.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} {{ __('scanned ·') }} {{ count($result->rows) }} {{ __('flagged') }}</x-slot:heading>

                <x-data-table :headers="['Order', 'Date', 'Email', 'Total', 'Payment', 'Risk']" :rows="$result->rows" empty="No medium or high risk orders were found in this range.">
                    @foreach ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3"><x-report.shopify-link :id="$row['id']">{{ $row['number'] }}</x-report.shopify-link></td>
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::date($row['created_at']) }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::number($row['total'], 2) }} {{ $row['currency'] }}</td>
                            <td class="px-4 py-3">{{ str_replace('_', ' ', $row['financial']) }}</td>
                            <td class="px-4 py-3">
                                <span class="font-semibold">{{ ucfirst($row['risk']['level']) }} · {{ $row['risk']['score'] }}</span>
                                <ul class="mt-1 list-disc pl-5 text-slate-500 dark:text-slate-400">
                                    @foreach ($row['risk']['signals'] as $signal)
                                        <li>{{ \App\Support\UiFormat::text($signal['label']) }} +{{ $signal['points'] }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
