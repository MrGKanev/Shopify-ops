@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Fulfillment audit"
        title="Carrier Performance"
        subtitle="Average delivery time and late-delivery rate grouped by ShipStation carrier."
        :configuration-error="$configurationError"
        credentials-message="ShipStation credentials are incomplete for the active store."
        :report-failed="$reportFailed"
        failure-message="The audit could not be completed. Check ShipStation and try again."
    >
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.carrier-performance.store')" :start-date="$startDate" :end-date="$endDate" submit-label="Run audit" />
        </x-slot:form>

        @if ($result)
            <x-report.results>
                <x-slot:heading>{{ $result->scanned }} shipments · {{ count($result->rows) }} carriers</x-slot:heading>

                <x-data-table
                    :rows="$result->rows"
                    empty="No shipments found."
                    :columns="[
                        'Carrier' => ['value' => 'carrier', 'class' => 'font-semibold'],
                        'Shipments' => 'count',
                        'With delivery date' => 'with_delivery',
                        'Avg delivery days' => fn (array $row): string => $row['avg_days'] === null ? '—' : $row['avg_days'].' days',
                        'Late deliveries' => 'late_count',
                        'Late %' => fn (array $row): string => $row['late_pct'] === null ? '—' : $row['late_pct'].'%',
                    ]"
                />
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
