@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Financial coverage" title="{{ __('Order Contribution Margin') }}" subtitle="Combine Shopify reporting and payment fees with ShipStation labels for each order." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.order-contribution.store')" :start-date="$startDate" :end-date="$endDate" />
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Select order creation dates. Analytics include subsequent sales adjustments through today in the Shopify reporting timezone. Each part examines up to 25 orders; use Next part to continue.') }}</p>
        </x-slot:form>
        <x-alert tone="warn">{{ __('This is contribution on the reported cost basis, not accounting net profit. Overhead, advertising, carrier billing adjustments, chargebacks and unverified label refunds are excluded. Missing costs are never zero.') }}</x-alert>
        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="This part does not cover the whole selection. Continue with Next part or review older-order access." :pages="$result->pages">
                <x-slot:heading>{{ __('Snapshot checked at :time (:timezone)', ['time' => \App\Support\UiFormat::date($result->meta['checkedAt'], true, $result->meta['timezone']), 'timezone' => $result->meta['timezone']]) }}</x-slot:heading>
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-stat-tile label="Orders scanned" :value="$result->scanned" />
                    <x-stat-tile label="Combined reported basis" :value="$result->meta['reported']" />
                    <x-stat-tile label="Incomplete cost coverage" :value="$result->meta['incomplete']" />
                </div>
                <x-card>
                    <p>{{ __('Shopify analytics use net product sales plus net shipping charges, excluding collected taxes and duties. Discounts and refunds are already reflected and are not deducted again.') }}</p>
                    <p class="mt-2 text-sm">{{ __('Historical product cost comes from Shopify analytics; current unitCost is not used. Cost coverage follows Shopify reporting and may not prove warehouse restocking or replacement-item cost.') }}</p>
                    <p class="mt-2 text-sm">{{ __('ShipStation label and insurance amounts are shown per label, including repeat and return labels. V1 may omit currency and external shipments; these costs cannot be combined without confirmed currency. Voided labels do not prove refunds.') }}</p>
                    @foreach ($result->meta['coverage'] as $message)<p class="mt-2 text-sm">{{ __($message) }}</p>@endforeach
                </x-card>
                <x-data-table :headers="['Order', 'Net revenue', 'Product cost', 'Payment fees', 'SS label costs', 'Contribution', 'Coverage']" :rows="$result->rows" empty="No orders in the available data.">
                    @foreach ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3">
                                <x-report.shopify-link :id="$row['shopify_id']">{{ $row['order_number'] }}</x-report.shopify-link>
                                <div>{{ \App\Support\UiFormat::date($row['created_at']) }}</div>
                            </td>
                            <td class="px-4 py-3">
                                {{ $row['revenue'] === null ? __('Unknown') : \App\Support\UiFormat::number($row['revenue'], 2).' '.$row['currency'] }}
                                @if ($row['revenue'] === null && $row['order_value_ex_tax'] !== null)
                                    <div class="mt-2 text-xs">{{ __('Current order value excluding tax (reference only): :amount', ['amount' => \App\Support\UiFormat::number($row['order_value_ex_tax'], 2).' '.$row['currency']]) }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                {{ $row['cogs'] === null ? __('Unknown') : \App\Support\UiFormat::number($row['cogs'], 2).' '.$row['currency'] }}
                                @if ($row['cogs'] === null && $row['reported_cogs'] !== null)<div class="text-xs">{{ __('Shopify reported cost with unconfirmed coverage: :amount', ['amount' => \App\Support\UiFormat::number($row['reported_cogs'], 2).' '.$row['currency']]) }}</div>@endif
                            </td>
                            <td class="px-4 py-3">
                                {{ $row['fees'] === null ? __('Unknown') : \App\Support\UiFormat::number($row['fees'], 2).' '.$row['currency'] }}
                                @if ($row['fees'] === null && $row['observed_fees'] !== null)<div class="text-xs">{{ __('Known fee portion: :amount', ['amount' => \App\Support\UiFormat::number($row['observed_fees'], 2).' '.$row['currency']]) }}</div>@endif
                            </td>
                            <td class="px-4 py-3">
                                {{ $row['shipping'] === null ? __('Unknown') : \App\Support\UiFormat::number($row['shipping'], 2).' '.$row['currency'] }}
                                @foreach ($row['labels'] as $label)
                                    <div class="mt-2 text-xs">
                                        {{ $label['id'] }} · {{ $label['carrier'] }} / {{ $label['service'] }}
                                        · {{ $label['return'] === true ? __('Return label') : ($label['return'] === false ? __('Outbound label') : __('Unknown label type')) }}
                                        · {{ $label['cost'] === null ? __('Unknown') : number_format($label['cost'], 2) }}
                                        + {{ $label['insurance'] === null ? __('Unknown') : number_format($label['insurance'], 2) }} {{ __('insurance') }}
                                        · {{ $label['currency'] ?? __('Currency unconfirmed') }}
                                        @if ($label['voided'] !== false) · {{ __('Void/refund status unconfirmed') }} @endif
                                    </div>
                                @endforeach
                            </td>
                            <td class="px-4 py-3">
                                {{ $row['contribution'] === null ? __('Incomplete') : \App\Support\UiFormat::number($row['contribution'], 2).' '.$row['currency'] }}
                                @if ($row['contribution'] === null && $row['shopify_contribution'] !== null)<div class="mt-2 text-xs">{{ __('Shopify basis before ShipStation costs: :amount', ['amount' => \App\Support\UiFormat::number($row['shopify_contribution'], 2).' '.$row['currency']]) }}</div>@endif
                            </td>
                            <td class="px-4 py-3">
                                <x-badge :tone="$row['contribution'] === null ? 'warn' : 'default'">{{ $row['contribution'] === null ? __('Incomplete') : __('Reported basis') }}</x-badge>
                                @foreach ($row['missing'] as $message)<div class="mt-2 text-xs">{{ __($message) }}</div>@endforeach
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
                @if ($result->meta['nextAfter'] !== null)
                    <x-button :href="route('reports.order-contribution.result', ['start_date' => $startDate, 'end_date' => $endDate, 'after' => $result->meta['nextAfter']])">{{ __('Next part') }}</x-button>
                @endif
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
