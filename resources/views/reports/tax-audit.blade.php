@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Compliance report" title="Tax Audit" subtitle="Review paid, non-exempt orders above the minimum where Shopify charged no tax." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form
                :action="route('reports.tax-audit.store')"
                :start-date="$startDate"
                :end-date="$endDate"
                :fields="['minimum' => ['label' => 'Minimum total', 'type' => 'number', 'value' => $minimum, 'min' => 0, 'step' => 1]]"
            />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results truncated after :pages pages." :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} scanned · {{ count($result->rows) }} zero-tax orders</x-slot:heading>

                <x-data-table :headers="['Order', 'Date', 'Email', 'Total']" :rows="$result->rows" empty="No qualifying zero-tax orders found.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-semibold"><x-report.shopify-link :id="$row['id']">{{ $row['number'] }}</x-report.shopify-link></td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['total'], 2) }} {{ $row['currency'] }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
