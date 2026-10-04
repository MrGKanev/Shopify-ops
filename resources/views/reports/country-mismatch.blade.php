@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Audit report" title="Billing ≠ shipping country" subtitle="Manual review queue for paid orders whose billing and shipping countries differ." :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.country-mismatch.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results were truncated after :pages pages." :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} scanned · {{ count($result->rows) }} mismatches</x-slot:heading>
                @if ($result->meta['skippedMissingCountry'])
                    <x-slot:summary>{{ $result->meta['skippedMissingCountry'] }} skipped because an ISO country code was missing.</x-slot:summary>
                @endif

                @forelse ($result->rows as $row)
                    <x-card>
                        <div class="flex justify-between gap-4">
                            <div>
                                <x-report.shopify-link :id="$row['id']" class="font-semibold">{{ $row['number'] }}</x-report.shopify-link>
                                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $row['created_at'] }} · {{ $row['email'] ?: 'No email' }}</p>
                            </div>
                            <strong>{{ number_format($row['total'], 2) }} {{ $row['currency'] }}</strong>
                        </div>
                        <div class="mt-3 flex gap-3">
                            <span>Billing: {{ $row['billing_country'] }}@if ($row['billing_name']) · {{ $row['billing_name'] }}@endif</span>
                            <span>Shipping: {{ $row['shipping_country'] }}</span>
                            <span>{{ $row['financial'] }} · {{ $row['fulfillment'] ?: 'unfulfilled' }}</span>
                        </div>
                        <a class="mt-3 inline-flex text-sm text-indigo-600 dark:text-indigo-400" href="{{ route('orders.spot-check', ['prefill' => ltrim($row['number'], '#')]) }}">{{ __('Open in spot-check') }}</a>
                    </x-card>
                @empty
                    <x-empty-state icon="🌍" title="No mismatches">No billing and shipping country mismatches were found.</x-empty-state>
                @endforelse
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
