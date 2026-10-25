@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Delivery exceptions" :title="__('Shipment movement monitoring')" :subtitle="__('Bounded checks of recent labels, with oldest observations checked first.')" :configuration-error="$configurationError" credentials-message="Verify the V2 tracking key, carrier coverage and API costs in Store settings before monitoring." :report-failed="$reportFailed" failure-message="Monitoring could not complete. Check access, the UTC daily cap and the selected shipment cohort.">
        <x-slot:form>
            <x-report.params-form :action="route('reports.shipment-movement-monitor.store')" submit-label="Run bounded monitoring" />
            @can('manage-administration')<x-button variant="ghost" :href="route('admin.report-schedules.create', ['tool' => 'shipment_movement_monitor'])">{{ __('Schedule tracking monitoring') }}</x-button>@endcan
        </x-slot:form>
        <x-alert>{{ __('Limits and allowed carriers are configured per store. Manual and automatic checks share the UTC daily read cap. Failed API attempts consume the cap. Missing labels or unknown tracking evidence never resolve issues.') }}</x-alert>
        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Only part of the eligible cohort was checked within the configured limits. This is not a complete store-wide delivery report.">
                <x-slot:heading>{{ $result->meta['captured_at'] }}</x-slot:heading>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-stat-tile label="Movement findings" :value="$result->meta['exceptions']" />
                    <x-stat-tile label="Labels checked" :value="$result->meta['checked_labels']" />
                    <x-stat-tile label="Eligible labels not checked" :value="$result->meta['not_checked']" />
                    <x-stat-tile label="Tracking reads remaining today (UTC)" :value="$result->meta['budget_remaining']" />
                </div>
                <x-data-table :rows="$result->meta['observations']" :columns="['Order' => 'order_number', 'Tracking' => 'tracking_number', 'Movement state' => ['value' => fn ($row) => __($row['classification'])], 'Last carrier progress' => 'last_progress_at']" empty="No eligible tracking labels were checked." />
                <x-button variant="ghost" :href="route('operational-issues.index', ['source' => 'delivery_watch'])">{{ __('Delivery exceptions') }}</x-button>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
