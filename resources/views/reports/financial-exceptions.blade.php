@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Financial coverage" :title="__('Financial Exceptions')" subtitle="Review Shopify Payments payout anomalies using explicit rules and included transactions." :configuration-error="$configurationError" :report-failed="$reportFailed" failure-message="The report could not be completed. Check Shopify Payments availability, payout permissions and API health.">
        <x-slot:form>
            @if ($errors->any())<x-alert tone="error"><ul class="list-disc pl-4">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-alert>@endif
            <x-report.params-form :action="route('reports.financial-exceptions.store')" submit-label="Check payout anomalies" :fields="[
                'payout_id' => ['label' => 'Payout ID (blank to choose)', 'value' => request('payout_id'), 'maxlength' => 20],
                'max_age_days' => ['label' => 'Pending age limit since issue date (days)', 'type' => 'number', 'value' => request('max_age_days', 7), 'min' => 1, 'max' => 365, 'required' => true],
                'adjustment_threshold' => ['label' => 'Adjustment threshold in payout currency (blank disables)', 'value' => request('adjustment_threshold'), 'inputmode' => 'decimal'],
                'tolerance' => ['label' => 'Net comparison tolerance in payout currency', 'value' => request('tolerance', '0.01'), 'inputmode' => 'decimal', 'required' => true],
            ]" />
        </x-slot:form>
        <x-alert tone="warn">{{ __('Shopify-only anomaly checks, not bank reconciliation. Shopify status does not independently verify bank receipt. Daily sales are not compared with same-day payouts.') }}</x-alert>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Age is measured since payout issue date, not since its last status change. Thresholds apply in the selected payout currency; amounts in different currencies are never converted or combined.') }}</p>
        @if ($result)
            @if ($result->meta['mode'] === 'select')
                <x-data-table :headers="['Payout', 'Issued', 'Shopify status', 'Net', 'Action']" :rows="$result->meta['payouts']" empty="No Shopify Payments payouts were found.">
                    @foreach ($result->meta['payouts'] as $payout)
                        <tr><td class="px-4 py-3">{{ $payout['legacyResourceId'] }}</td><td class="px-4 py-3">{{ $payout['issuedAt'] ?? '—' }}</td><td class="px-4 py-3">{{ $payout['status'] ?? '—' }}</td><td class="px-4 py-3">{{ data_get($payout, 'net.amount') }} {{ data_get($payout, 'net.currencyCode') }}</td><td class="px-4 py-3"><x-button variant="ghost" :href="route('reports.financial-exceptions.result', [...$result->params, 'payout_id' => $payout['legacyResourceId'], 'after' => null])">{{ __('Inspect payout') }}</x-button></td></tr>
                    @endforeach
                </x-data-table>
                @if ($result->meta['next_after'])<x-button variant="ghost" :href="route('reports.financial-exceptions.result', [...$result->params, 'after' => $result->meta['next_after']])">{{ __('Next part') }}</x-button>@endif
            @else
                @php($summary = $result->meta['summary'])
                <x-card>
                    <h2 class="text-lg font-semibold">{{ __('Payout') }} #{{ $summary['payout_id'] }}</h2>
                    <p class="mt-2">{{ __('Shopify status') }}: {{ $summary['status'] }} · {{ $summary['direction'] }} · {{ __('Issued') }}: {{ $summary['issued_at'] }}</p>
                    <p class="mt-2">{{ __('Checked at') }}: {{ $summary['checked_at'] }}</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach (['payout_net' => 'Payout net', 'observed_component_net' => 'Observed component net (may be partial)', 'observed_fees' => 'Observed fees (may be partial)', 'confirmed_component_net' => 'Complete component net', 'delta' => 'Component net minus payout net'] as $key => $label)
                            <p class="text-sm">{{ __($label) }}: {{ $summary[$key] === null ? __('Unknown') : $summary[$key].' '.$summary['currency'] }}</p>
                        @endforeach
                    </div>
                    <p class="mt-3 text-sm">{{ __('Chosen policy') }}: {{ $summary['policy']['max_age_days'] }} {{ __('days') }} · {{ __('Tolerance') }}: {{ $summary['policy']['tolerance'] }} {{ $summary['currency'] }} · {{ __('Adjustment threshold') }}: {{ $summary['policy']['adjustment_threshold'] ?? __('Disabled') }} {{ $summary['currency'] }}</p>
                </x-card>
                @if (! $summary['complete'])<x-alert tone="warn">{{ __('Financial source coverage is incomplete; no confirmed total comparison is available.') }}</x-alert>@endif
                @foreach ($summary['notes'] as $note)<x-alert tone="warn">{{ __($note) }}</x-alert>@endforeach
                <x-data-table :headers="['Finding', 'Reference']" :rows="$result->rows" empty="No exceptions found under the chosen rules. This does not establish bank reconciliation or completeness.">
                    @foreach ($result->rows as $row)<tr><td class="px-4 py-3">{{ __($row['message']) }}</td><td class="px-4 py-3 break-all">{{ $row['reference'] }}</td></tr>@endforeach
                </x-data-table>
                <x-data-table :headers="['Transaction / type', 'Amount / fee / net', 'Linked order / transaction', 'Adjustment details']" :rows="$result->meta['transactions']" empty="No confirmed payout component transactions were found.">
                    @foreach ($result->meta['transactions'] as $transaction)
                        <tr>
                            <td class="px-4 py-3 break-all"><div>{{ $transaction['id'] }}</div><div>{{ $transaction['type'] }} · {{ $transaction['source_type'] }}</div><div>{{ $transaction['date'] }}</div></td>
                            <td class="px-4 py-3">@foreach (['amount', 'fee', 'net'] as $key)<div>{{ ucfirst($key) }}: {{ data_get($transaction, $key.'.amount', '—') }} {{ data_get($transaction, $key.'.currencyCode', '') }}</div>@endforeach</td>
                            <td class="px-4 py-3 break-all">{{ $transaction['order_name'] ?: '—' }}<div>{{ $transaction['order_transaction_id'] ?? '—' }}</div><div>{{ $transaction['order_id'] ?? '—' }}</div></td>
                            <td class="px-4 py-3"><div>{{ $transaction['adjustment_reason'] ?: '—' }}</div>@foreach ($transaction['adjustment_orders'] as $adjustment)<div>{{ $adjustment['name'] ?? '—' }} · {{ $adjustment['orderTransactionId'] ?? '—' }} · {{ data_get($adjustment, 'amount.amount') }} {{ data_get($adjustment, 'amount.currencyCode') }}</div>@endforeach</td>
                        </tr>
                    @endforeach
                </x-data-table>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Adjustment order breakdowns are informational and are not added again to transaction totals. Missing order links are not assumed to be errors.') }}</p>
            @endif
        @endif
    </x-report.layout>
@endsection
