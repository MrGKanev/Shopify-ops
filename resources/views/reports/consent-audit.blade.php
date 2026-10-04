@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Compliance report" title="{{ __('Marketing Consent Audit') }}" subtitle="Find paid orders from customers who are not actively subscribed to email marketing. SMS consent is informational." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.consent-audit.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} {{ __('scanned ·') }} {{ count($result->rows) }} {{ __('without active email consent') }}</x-slot:heading>

                <x-data-table :headers="['Order', 'Date', 'Email', 'Email consent', 'SMS consent', 'Total']" :rows="$result->rows" empty="All customers in this range are actively subscribed to email marketing.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3"><x-report.shopify-link :id="$row['id']">{{ $row['number'] }}</x-report.shopify-link></td>
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::date($row['created_at']) }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ str_replace('_', ' ', $row['email_consent']) }}</td>
                            <td class="px-4 py-3">{{ str_replace('_', ' ', $row['sms_consent']) }}</td>
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::number($row['total'], 2) }} {{ $row['currency'] }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
