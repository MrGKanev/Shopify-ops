@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Audit report" title="{{ __('High-value orders without phone') }}" subtitle="Paid, unfulfilled orders whose shipping phone is missing or not valid for the shipping country." :report-failed="$reportFailed" failure-message="The report could not be completed. Check the Shopify integration and try again.">
        <x-slot:form>
            <x-report.date-range-form
                :action="route('reports.high-value-no-phone.store')"
                :start-date="$startDate"
                :end-date="$endDate"
                :fields="[
                    'minimum' => ['label' => 'Minimum value', 'type' => 'number', 'value' => $minimum, 'min' => 0, 'step' => '0.01'],
                    'currency' => ['label' => 'Currency', 'value' => $currency, 'maxlength' => 3, 'list' => 'currency-options', 'class' => 'uppercase'],
                ]"
            >
                <datalist id="currency-options"><option value="ALL">{{ __('All currencies') }}</option></datalist>
            </x-report.date-range-form>
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results were truncated after :pages pages." :pages="$result->pages">
                <x-slot:heading>{{ __('Results') }}</x-slot:heading>
                <x-slot:summary>{{ $result->scanned }} {{ __('scanned ·') }} {{ \App\Support\UiFormat::count(count($result->rows), 'issues') }}</x-slot:summary>

                @forelse ($result->rows as $row)
                    <x-card>
                        <div class="flex justify-between gap-4">
                            <div>
                                <x-report.shopify-link :id="$row['id']" class="font-semibold">{{ $row['number'] }}</x-report.shopify-link>
                                <p class="text-sm text-slate-500 dark:text-slate-400">{{ \App\Support\UiFormat::date($row['created_at']) }} · {{ $row['email'] ?: 'No email' }}</p>
                                <p class="mt-2 text-sm">{{ $row['recipient'] }}@if ($row['recipient'] && $row['address']), @endif{{ $row['address'] }}</p>
                                <p class="mt-2">
                                    @if ($row['phone_issue'] === 'invalid')
                                        <x-badge tone="warn">{{ __('Invalid phone') }}</x-badge> <span class="text-sm">{{ $row['phone'] }}</span>
                                    @else
                                        <x-badge tone="danger">{{ __('No phone') }}</x-badge>
                                    @endif
                                </p>
                            </div>
                            <strong>{{ \App\Support\UiFormat::number($row['total'], 2) }} {{ $row['currency'] }}</strong>
                        </div>
                        <a class="mt-3 inline-flex text-sm text-indigo-600 dark:text-indigo-400" href="{{ route('orders.spot-check', ['prefill' => ltrim($row['number'], '#')]) }}">{{ __('Open in spot-check') }}</a>
                    </x-card>
                @empty
                    <x-empty-state icon="✓">{{ __('No high-value orders without a valid shipping phone were found.') }}</x-empty-state>
                @endforelse
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
