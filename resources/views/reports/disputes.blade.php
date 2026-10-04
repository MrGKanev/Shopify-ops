@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Risk report" title="Chargebacks / Disputes" subtitle="Open Shopify Payments disputes sorted by response deadline." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.params-form :action="route('reports.disputes.store')" submit-label="Scan disputes" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results are incomplete: disputes truncated after :pages pages." :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} open disputes</x-slot:heading>

                <x-data-table :headers="['Order', 'Status', 'Reason', 'Amount', 'Initiated', 'Days until due']" :rows="$result->rows" empty="No open disputes need a response.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3"><x-report.shopify-link :id="$row['order_id']">{{ $row['order_name'] ?: '—' }}</x-report.shopify-link></td>
                            <td class="px-4 py-3">{{ str_replace('_', ' ', $row['status']) }}</td>
                            <td class="px-4 py-3">{{ str_replace('_', ' ', $row['reason']) }}</td>
                            <td class="px-4 py-3">{{ number_format($row['amount'], 2) }} {{ $row['currency'] }}</td>
                            <td class="px-4 py-3">{{ substr($row['initiated_at'], 0, 10) }}</td>
                            <td class="px-4 py-3">{{ $row['days_until_due'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
