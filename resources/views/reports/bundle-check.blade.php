@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Order audit" title="{{ __('Bundle Check') }}" :subtitle="$description" :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            @if ($ruleNames)
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Active rules:') }} {{ implode(', ', $ruleNames) }}</p>
            @endif

            <x-report.date-range-form :action="route('reports.bundle-check.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results
                export-route="reports.bundle-check.export"
                :export-params="['start_date' => $result->params['startDate'], 'end_date' => $result->params['endDate']]"
                :truncated="$result->truncated"
                truncated-message="Results are incomplete: Shopify orders were truncated after :pages pages."
                :pages="$result->pages"
            >
                <x-slot:heading>{{ $result->scanned }} {{ __('orders scanned ·') }} {{ \App\Support\UiFormat::count(count($result->rows), 'incomplete bundles') }}</x-slot:heading>

                <x-data-table
                    :rows="$result->rows"
                    empty="All bundles are complete."
                    :columns="[
                        'Order' => ['value' => 'order_number', 'class' => 'font-semibold'],
                        'Date' => 'created_at',
                        'Type' => 'order_type',
                        'Missing items' => 'missing_text',
                        'Fulfillment' => fn (array $row): string => __($row['fulfillment_status'] ?: 'unfulfilled'),
                        'Financial' => 'financial_status',
                        'Email' => fn (array $row): string => $row['email'] ?: '—',
                    ]"
                />
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
