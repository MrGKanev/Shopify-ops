@extends('layouts.app')

@section('content')
    @php
        $orderInput = old('order_number', $result?->params['orderNumber'] ?? request('order_number', ''));
        $acceptanceInput = old('acceptance_hours', $result?->params['acceptanceHours'] ?? 48);
        $staleInput = old('stale_hours', $result?->params['staleHours'] ?? 72);
    @endphp
    <x-report.layout eyebrow="Delivery exceptions" :title="__('Shipment movement check')" :subtitle="__('Check one order using current carrier tracking. This runs only when requested.')" :configuration-error="$configurationError" credentials-message="Configure ShipStation V1 credentials, its store number and a separate V2 tracking API key for this store." :report-failed="$reportFailed" failure-message="The movement check could not be completed. Verify tracking API access and shipment identity.">
        <x-slot:form>
            <x-card>
                <form method="POST" action="{{ route('reports.shipment-movement.store') }}" class="flex flex-col gap-4">
                    @csrf
                    <label class="flex flex-col gap-2">{{ __('Order number') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" name="order_number" required maxlength="100" value="{{ is_scalar($orderInput) ? $orderInput : '' }}"></label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex flex-col gap-2">{{ __('Acceptance grace (hours from label creation)') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" name="acceptance_hours" type="number" min="1" max="720" required value="{{ is_scalar($acceptanceInput) ? $acceptanceInput : 48 }}"></label>
                        <label class="flex flex-col gap-2">{{ __('No-progress threshold (hours)') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" name="stale_hours" type="number" min="1" max="2160" required value="{{ is_scalar($staleInput) ? $staleInput : 72 }}"></label>
                    </div>
                    @if ($errors->any())<x-alert tone="error">{{ $errors->first() }}</x-alert>@endif
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Checks up to ten labels for one verified store order. Tracking reads may count against your API plan. Findings appear in Delivery exceptions for ownership and claim review dates.') }}</p>
                    <x-button type="submit">{{ __('Check shipment movement') }}</x-button>
                </form>
            </x-card>
        </x-slot:form>
        <x-alert>{{ __('Label creation is not carrier handover. No acceptance record means the carrier currently reports Not Yet In System. Unknown statuses or missing event times remain unknown; they do not resolve existing issues.') }}</x-alert>
        @if ($result)
            <x-report.results>
                <x-slot:heading>{{ $result->meta['captured_at'] }} · {{ $result->meta['exceptions'] }} {{ __('movement findings') }}</x-slot:heading>
                <x-data-table :headers="['Tracking', 'Carrier', 'Movement state', 'First observed carrier event', 'Last carrier progress', 'Hours', 'Carrier status']" :rows="$result->rows" empty="No non-voided tracked shipments found.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-mono">{{ $row['tracking_number'] }}</td>
                            <td class="px-4 py-3">{{ $row['carrier'] }}</td>
                            <td class="px-4 py-3"><x-badge :tone="$row['actionable'] ? 'warn' : 'default'">{{ __($row['classification']) }}</x-badge></td>
                            <td class="px-4 py-3">{{ $row['accepted_at'] ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $row['last_progress_at'] ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $row['hours'] ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $row['source_status'] }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
                <x-button variant="ghost" :href="route('operational-issues.index', ['source' => 'delivery_watch'])">{{ __('Delivery exceptions') }}</x-button>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
