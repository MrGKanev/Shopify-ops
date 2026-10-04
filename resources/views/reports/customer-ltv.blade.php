@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Customer report" title="{{ __('Customer LTV & Cohorts') }}" subtitle="Top customers by non-cancelled revenue and monthly repeat-buyer cohorts within the selected period." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.customer-ltv.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} {{ __('orders ·') }} {{ $result->meta['customers'] }} {{ __('customers · $') }}{{ \App\Support\UiFormat::number($result->meta['revenue'], 2) }}</x-slot:heading>

                <div class="flex flex-col gap-2">
                    <h3 class="text-lg font-semibold">{{ __('Top customers') }}</h3>
                    <x-data-table
                        :rows="$result->rows"
                        empty="No customers found."
                        :columns="[
                            'Email' => 'email',
                            'Orders' => 'orders',
                            'Total' => fn (array $customer): string => '$'.\App\Support\UiFormat::number($customer['total'], 2),
                            'Average' => fn (array $customer): string => '$'.\App\Support\UiFormat::number($customer['average'], 2),
                            'First / last' => fn (array $customer): string => $customer['first_date'].' / '.$customer['last_date'],
                        ]"
                    />
                </div>

                @if ($result->meta['cohorts'])
                    <div class="flex flex-col gap-2">
                        <h3 class="text-lg font-semibold">{{ __('Monthly cohorts') }}</h3>
                        <x-data-table
                            :rows="$result->meta['cohorts']"
                            :columns="[
                                'Month' => 'month',
                                'Customers' => 'customers',
                                'Repeat buyers' => 'repeat_buyers',
                                'Repeat rate' => fn (array $cohort): string => $cohort['retention_rate'].'%',
                                'Avg orders' => 'average_orders',
                                'Revenue / customer' => fn (array $cohort): string => '$'.\App\Support\UiFormat::number($cohort['average_revenue'], 2),
                            ]"
                        />
                    </div>
                @endif
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
