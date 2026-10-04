@extends('layouts.app')

@section('content')
    <x-report.layout
        eyebrow="Operations"
        title="Run audit"
        subtitle="Find active Shopify orders missing from ShipStation."
        :configuration-error="$configurationError"
        credentials-message="Shopify and ShipStation credentials are required for the active store."
        :report-failed="$reportFailed"
        failure-message="The audit could not be completed. Check both integrations and try again."
    >
        <x-slot:form>
            @if (session('status'))
                <x-alert tone="ok">{{ session('status') }}</x-alert>
            @endif
            @error('queue')
                <x-alert tone="error">{{ __($message) }}</x-alert>
            @enderror

            <x-report.date-range-form :action="route('reports.run-audit.store')" :start-date="$startDate" :end-date="$endDate" submit-label="Run now" :columns="4">
                <x-slot:actions>
                    <x-button type="submit" variant="ghost" formaction="{{ route('reports.run-audit.queue') }}">Queue</x-button>
                </x-slot:actions>
            </x-report.date-range-form>
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->meta['shopifyTruncated']" truncated-message="Results are incomplete because Shopify data was truncated.">
                <x-slot:heading>{{ $result->meta['shopifyTotal'] }} Shopify orders · {{ count($result->rows) }} missing</x-slot:heading>

                <div class="grid gap-3 sm:grid-cols-4">
                    <x-card padding="p-4">Found: {{ $result->meta['found'] }}</x-card>
                    <x-card padding="p-4">Skipped: {{ $result->meta['skipped'] }}</x-card>
                    <x-card padding="p-4">Ignored: {{ $result->meta['ignored'] }}</x-card>
                    <x-card padding="p-4">ShipStation: {{ $result->meta['shipstationTotal'] }}</x-card>
                </div>

                @if ($result->meta['duplicates'] !== [])
                    <x-alert tone="warn">
                        <strong>{{ count($result->meta['duplicates']) }} potential duplicate{{ count($result->meta['duplicates']) === 1 ? '' : 's' }} detected</strong> {{ __('— same email and amount within 24 hours.') }}
                        <ul class="mt-2 list-disc pl-5">
                            @foreach ($result->meta['duplicates'] as $cluster)
                                <li>{{ $cluster['email'] }} · {{ number_format((float) $cluster['amount'], 2) }} — {{ implode(', ', array_map(fn ($order) => $order['name'] ?? $order['order_number'] ?? '?', $cluster['orders'])) }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif

                @if ($result->rows !== [])
                    @include('partials.bulk-ignore-form')
                @endif

                <x-data-table :headers="['', 'Order', 'Date', 'Email', 'Total']" :rows="$result->rows" empty="Every eligible Shopify order was found in ShipStation.">
                    @foreach ($result->rows as $order)
                        @php($number = $order['name'] ?? $order['order_number'] ?? '')
                        <tr>
                            <x-report.ignore-checkbox :number="$number" />
                            <td class="px-4 py-3 font-semibold">{{ $number ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $order['created_at'] ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $order['email'] ?? '—' }}</td>
                            <td class="px-4 py-3">{{ number_format((float) ($order['total_price'] ?? 0), 2) }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
