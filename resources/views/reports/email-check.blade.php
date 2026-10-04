@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Compliance report" title="{{ __('Email Checker') }}" subtitle="Find paid orders with missing, invalid, disposable, or suspicious email addresses." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.email-check.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} {{ __('scanned ·') }} {{ $result->meta['critical'] }} {{ __('critical ·') }} {{ \App\Support\UiFormat::count($result->meta['warnings'], 'warnings') }}</x-slot:heading>

                @if ($result->rows !== [])
                    @include('partials.bulk-ignore-form')
                @endif

                <x-data-table :headers="['Select', 'Severity', 'Order', 'Date', 'Email', 'Issues']" :rows="$result->rows" empty="No email issues were found in this range.">
                    @foreach ($result->rows as $row)
                        <tr class="align-top">
                            <x-report.ignore-checkbox :number="$row['number']" />
                            <td class="px-4 py-3 font-semibold">{{ __(ucfirst($row['severity'])) }}</td>
                            <td class="px-4 py-3"><x-report.shopify-link :id="$row['id']">{{ $row['number'] }}</x-report.shopify-link></td>
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::date($row['created_at']) }}</td>
                            <td class="px-4 py-3">{{ $row['email'] ?: __('Missing') }}</td>
                            <td class="px-4 py-3">
                                <ul class="list-disc pl-5">
                                    @foreach ($row['issues'] as $issue)
                                        <li>{{ \App\Support\UiFormat::text($issue['message']) }}</li>
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
