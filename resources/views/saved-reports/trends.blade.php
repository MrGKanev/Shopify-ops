@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="{{ __('Audit Trends') }}" subtitle="Daily missing-order totals from saved Run Audit snapshots." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="GET">
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ __($label) }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end">
                <x-button type="submit">{{ __('Apply') }}</x-button>
            </div>
        </form>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-tile label="Avg missing / report" :value="$avgMissing" />
            <x-stat-tile label="Clear reports" :value="$clearReportCount" />
            <x-stat-tile label="Worst report" :value="$worstReport?->rows_found ?? '—'">
                @if ($worstReport)
                    <x-slot:sub><a class="text-indigo-600 dark:text-indigo-400" href="{{ route('saved-reports.show', $worstReport) }}">{{ \App\Support\UiFormat::date($worstReport->report_date) }}</a></x-slot:sub>
                @endif
            </x-stat-tile>
            <x-stat-tile label="Unique missing orders" :value="$uniqueMissingCount" />
        </div>

        <x-data-table :headers="['Date', 'Missing', 'Change', 'Trend']">
            @forelse ($rows as $row)
                <tr>
                    <td class="px-4 py-3"><a class="text-indigo-600 dark:text-indigo-400" href="{{ route('saved-reports.show', $row['id']) }}">{{ $row['date'] }}</a></td>
                    <td class="px-4 py-3">{{ $row['missing'] }}</td>
                    <td class="px-4 py-3">@if ($row['delta'] === null) — @elseif ($row['delta'] > 0) +{{ $row['delta'] }} @else {{ $row['delta'] }} @endif</td>
                    <td class="w-1/2 px-4 py-3"><style nonce="{{ Vite::cspNonce() }}">#trend-chart-bar-{{ $loop->index }} { width: {{ $maximum > 0 ? round($row['missing'] / $maximum * 100) : 0 }}%; }</style><div id="trend-chart-bar-{{ $loop->index }}" class="h-3 rounded bg-indigo-500"></div></td>
                </tr>
            @empty
                <tr>
                    <td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="4">{{ __('No saved audit data for this period.') }}</td>
                </tr>
            @endforelse
        </x-data-table>

        <section class="flex flex-col gap-3">
            <h2 class="text-xl font-bold">{{ __('Shipment backlog history') }}</h2>
            @can('manage-administration')<div><x-button variant="ghost" :href="route('admin.report-schedules.index')">{{ __('Report schedules') }}</x-button></div>@endcan
            <p class="text-sm text-slate-600 dark:text-slate-400">{{ __('Daily ShipStation awaiting-shipment and on-hold orders. Age is elapsed time since the order date; groups include both statuses. These are observations during a scan, not end-of-day totals or dispatch counts.') }}</p>
            <x-alert tone="warn">{{ __('Collection is controlled independently in Report schedules. The first successful observation of each store-local day is preserved. Missing days are unknown, not zero. Weekly changes require an observation exactly seven days earlier.') }}</x-alert>
            <x-data-table :headers="['Date', 'Captured at', 'Awaiting shipment', 'On hold', 'Total', 'Under 24h', '1–2 days', '3–6 days', '7+ days', 'Unknown age', 'Change vs prior week', '']" :rows="$backlogRows" empty="No backlog observations for this period.">
                @foreach ($backlogRows as $row)
                    <tr>
                        <td class="px-4 py-3">{{ $row['date'] }}</td>
                        <td class="px-4 py-3">{{ $row['captured_at'] }}</td>
                        <td class="px-4 py-3">{{ $row['awaiting'] }}</td>
                        <td class="px-4 py-3">{{ $row['on_hold'] }}</td>
                        <td class="px-4 py-3 font-semibold">{{ $row['total'] }}</td>
                        @foreach (['under_1_day', '1_2_days', '3_6_days', '7_plus_days', 'unknown'] as $bucket)
                            <td class="px-4 py-3">{{ $row['age_buckets'][$bucket] }}</td>
                        @endforeach
                        <td class="px-4 py-3">{{ $row['weekly_delta'] === null ? '—' : ($row['weekly_delta'] > 0 ? '+' : '').$row['weekly_delta'] }}</td>
                        <td class="px-4 py-3"><x-button size="sm" variant="ghost" :href="route('saved-reports.export', $row['id'])">{{ __('Download CSV') }}</x-button></td>
                    </tr>
                @endforeach
            </x-data-table>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-xl font-bold">{{ __('Shopify fulfillment backlog') }}</h2>
            @can('manage-administration')<div><x-button variant="ghost" :href="route('admin.report-schedules.create', ['tool' => 'shopify_backlog'])">{{ __('Schedule Shopify backlog') }}</x-button></div>@endcan
            <p class="text-sm text-slate-600 dark:text-slate-400">{{ __('Remaining physical-shipping units from visible Shopify fulfillment orders, including partial, held and scheduled work. Cancelled, closed and test work is excluded.') }}</p>
            <x-alert tone="warn">{{ __('Coverage follows the integration’s granted access scopes; inaccessible work and older orders may be absent. Values are estimates from remaining units × Shopify’s approximate discounted unit price in the order currency. Shipping fees are excluded; tax inclusion follows the source price. These are not receivables, dispatch counts or revenue.') }}</x-alert>
            <p class="text-sm text-slate-600 dark:text-slate-400">{{ __('The first successful daily observation is preserved. Missing or incomplete reads never become zero. Weekly quantity comparisons require the same account, access scopes and timezone exactly seven days earlier.') }}</p>
            <x-data-table :headers="['Date', 'Captured at', 'Orders', 'Fulfillment groups', 'Remaining shipping units', 'Change vs prior week', 'Estimated merchandise value', 'Coverage', '']" :rows="$shopifyBacklogRows" empty="No Shopify backlog observations for this period.">
                @foreach ($shopifyBacklogRows as $row)
                    <tr>
                        <td class="px-4 py-3">{{ $row['date'] }}</td>
                        <td class="px-4 py-3">{{ $row['captured_at'] }}</td>
                        <td class="px-4 py-3">{{ $row['orders'] }}</td>
                        <td class="px-4 py-3">{{ $row['fulfillment_orders'] }}</td>
                        <td class="px-4 py-3 font-semibold">{{ $row['remaining_units'] }}</td>
                        <td class="px-4 py-3">{{ $row['weekly_units_delta'] === null ? '—' : ($row['weekly_units_delta'] > 0 ? '+' : '').$row['weekly_units_delta'] }}</td>
                        <td class="px-4 py-3">
                            @foreach ($row['currencies'] as $currency)
                                <div>{{ $currency['currency'] }} {{ $currency['estimated_value'] ?? __('Unknown') }}</div>
                            @endforeach
                            @if ($row['unpriced_units'])<x-badge tone="warn">{{ $row['unpriced_units'] }} {{ __('unpriced units') }}</x-badge>@endif
                        </td>
                        <td class="px-4 py-3"><details><summary>{{ __('Visible fulfillment orders') }}</summary><ul>@foreach ($row['scopes'] as $scope)<li class="break-all text-xs">{{ $scope }}</li>@endforeach</ul></details></td>
                        <td class="px-4 py-3"><x-button size="sm" variant="ghost" :href="route('saved-reports.export', $row['id'])">{{ __('Download CSV') }}</x-button></td>
                    </tr>
                @endforeach
            </x-data-table>
        </section>

        @if ($repeatOffenders !== [])
            <section class="flex flex-col gap-3">
                <h2 class="text-xl font-bold">{{ __('Repeat offenders') }}</h2>
                @include('partials.bulk-ignore-form')
                <x-data-table :headers="['Select', 'Order', 'Times missing', '']">
                    @foreach ($repeatOffenders as $offender)
                        <tr>
                            <x-report.ignore-checkbox :number="$offender['number']" />
                            <td class="px-4 py-3">{{ $offender['number'] }}</td>
                            <td class="px-4 py-3">{{ $offender['count'] }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('ignored-orders.store') }}">
                                    @csrf
                                    <input type="hidden" name="order_number" value="{{ $offender['number'] }}">
                                    <input type="hidden" name="reason" value="Repeat offender">
                                    <x-button type="submit" size="sm" variant="danger">{{ __('Ignore') }}</x-button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
