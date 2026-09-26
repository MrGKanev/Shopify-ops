@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Customer report" title="Customer LTV & Cohorts" subtitle="Top customers by non-cancelled revenue and monthly repeat-buyer cohorts within the selected period." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.customer-ltv.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="text-sm text-red-600">{{ __($message) }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end">
                <x-button type="submit">{{ __('Run report') }}</x-button>
            </div>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} orders · {{ $result->customers }} customers · ${{ number_format($result->revenue, 2) }}</h2>
                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: orders truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                <div class="flex flex-col gap-2">
                    <h3 class="text-lg font-semibold">{{ __('Top customers') }}</h3>
                    <x-data-table :headers="['Email', 'Orders', 'Total', 'Average', 'First / last']">
                        @forelse ($result->topCustomers as $customer)
                            <tr>
                                <td class="px-4 py-3">{{ $customer['email'] }}</td>
                                <td class="px-4 py-3">{{ $customer['orders'] }}</td>
                                <td class="px-4 py-3">${{ number_format($customer['total'], 2) }}</td>
                                <td class="px-4 py-3">${{ number_format($customer['average'], 2) }}</td>
                                <td class="px-4 py-3">{{ $customer['first_date'] }} / {{ $customer['last_date'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-4 py-8 text-center text-slate-500" colspan="5">{{ __('No customers found.') }}</td>
                            </tr>
                        @endforelse
                    </x-data-table>
                </div>

                @if ($result->cohorts)
                    <div class="flex flex-col gap-2">
                        <h3 class="text-lg font-semibold">{{ __('Monthly cohorts') }}</h3>
                        <x-data-table :headers="['Month', 'Customers', 'Repeat buyers', 'Repeat rate', 'Avg orders', 'Revenue / customer']">
                            @foreach ($result->cohorts as $cohort)
                                <tr>
                                    <td class="px-4 py-3">{{ $cohort['month'] }}</td>
                                    <td class="px-4 py-3">{{ $cohort['customers'] }}</td>
                                    <td class="px-4 py-3">{{ $cohort['repeat_buyers'] }}</td>
                                    <td class="px-4 py-3">{{ $cohort['retention_rate'] }}%</td>
                                    <td class="px-4 py-3">{{ $cohort['average_orders'] }}</td>
                                    <td class="px-4 py-3">${{ number_format($cohort['average_revenue'], 2) }}</td>
                                </tr>
                            @endforeach
                        </x-data-table>
                    </div>
                @endif
            </section>
        @endif
    </div>
@endsection
