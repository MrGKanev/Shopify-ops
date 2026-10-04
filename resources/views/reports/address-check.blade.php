@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Fulfillment report" title="{{ __('Address Scanner') }}" subtitle="Find incomplete, invalid, short, or carrier-incompatible shipping addresses on paid orders." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.address-check.store')" :start-date="$startDate" :end-date="$endDate">
                <label><input name="unfulfilled_only" type="checkbox" value="1" @checked(old('unfulfilled_only', $unfulfilledOnly))> {{ __('Unfulfilled only') }}</label>
                <label><input name="po_box_only" type="checkbox" value="1" @checked(old('po_box_only', $poBoxOnly))> {{ __('PO Box issues only') }}</label>
            </x-report.date-range-form>
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} {{ __('scanned ·') }} {{ $result->meta['critical'] }} {{ __('critical ·') }} {{ \App\Support\UiFormat::count($result->meta['warnings'], 'warnings') }}</x-slot:heading>

                @if ($result->rows !== [])
                    @include('partials.bulk-ignore-form')
                @endif

                <x-data-table :headers="['Select', 'Severity', 'Order', 'Email', 'Address', 'Issues']" :rows="$result->rows" empty="No address issues were found.">
                    @foreach ($result->rows as $row)
                        <tr class="align-top">
                            <x-report.ignore-checkbox :number="$row['number']" />
                            <td class="px-4 py-3 font-semibold">{{ __(ucfirst($row['severity'])) }}</td>
                            <td class="px-4 py-3"><x-report.shopify-link :id="$row['id']">{{ $row['number'] }}</x-report.shopify-link><br>{{ \App\Support\UiFormat::date($row['created_at']) }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ $row['address']['address1'] ?? 'Missing' }}<br>{{ $row['address']['city'] ?? '' }} {{ $row['address']['zip'] ?? '' }} {{ $row['address']['country_code'] ?? '' }}</td>
                            <td class="px-4 py-3">
                                <ul class="list-disc pl-5">
                                    @foreach ($row['issues'] as $issue)
                                        <li>{{ __($issue['message']) }}</li>
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
