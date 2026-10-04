@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Operations report" title="{{ __('Note Flags') }}" subtitle="Find paid, unfulfilled orders whose notes need attention." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form
                :action="route('reports.note-flags.store')"
                :start-date="$startDate"
                :end-date="$endDate"
                :fields="['keywords' => ['label' => 'Keywords', 'value' => $keywords]]"
            />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} {{ __('scanned ·') }} {{ \App\Support\UiFormat::count(count($result->rows), 'flagged notes') }}</x-slot:heading>

                <x-data-table :headers="['Order', 'Placed', 'Matched', 'Note', 'Email']" :rows="$result->rows" empty="No order notes matched the keywords.">
                    @foreach ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3"><x-report.shopify-link :id="$row['shopify_id']">{{ $row['order_number'] }}</x-report.shopify-link></td>
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::date($row['created_at']) }}</td>
                            <td class="px-4 py-3">{{ implode(', ', $row['matched']) }}</td>
                            <td class="px-4 py-3">{{ $row['note'] }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
