@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Operations" :title="__('Order arrivals and carrier acceptance')" :subtitle="__('Two measured event streams for completed store-local days.')" :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form><x-report.date-range-form :action="route('reports.shipment-activity.store')" :start-date="$startDate" :end-date="$endDate" /></x-slot:form>
        <x-alert>{{ __('Created orders use Shopify creation timestamps, exclude test orders and include later cancellations and digital orders. Carrier counts use dated AC acceptance events recorded from checked labels only. They are not all dispatches, and these two cohorts must not be subtracted to reconstruct backlog.') }}</x-alert>
        @if ($result)
            <x-report.results export-route="reports.shipment-activity.export" :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate'], 'report_run' => request()->query('report_run') ? null : request()->attributes->get('scheduleSourceRun')?->getKey()]">
                <x-slot:heading>{{ $result->meta['timezone'] }} · {{ $result->meta['captured_at'] }}</x-slot:heading>
                <p class="text-sm">{{ __('Tracking observed as of') }}: {{ $result->meta['tracking_observed_as_of'] ?? __('No tracking observations') }}</p>
                <x-alert tone="warn">{{ __('A blank carrier count means no tracking observations exist for this account. Zero means no acceptance event is recorded in the observed subset, not zero actual dispatches. Older order ranges require full Shopify order-history access.') }}</x-alert>
                <x-data-table :columns="['Date' => 'date', 'Created orders (Shopify)' => 'created_orders', 'Recorded carrier acceptances (observed labels only)' => 'recorded_carrier_acceptances']" :rows="$result->rows" />
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
