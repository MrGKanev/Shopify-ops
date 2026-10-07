@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Operations" title="{{ __('Operational Digest') }}" subtitle="Pending fulfillment, sync findings, unresolved issues and upcoming deadlines in one snapshot." :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.operational-digest.store')" :start-date="$startDate" :end-date="$endDate" :columns="4" submit-label="Build snapshot" :fields="['threshold' => ['label' => 'Fulfillment SLA (calendar days)', 'type' => 'number', 'value' => $threshold, 'min' => 1, 'max' => 365, 'required' => true]]" />
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Order checks cover the selected creation dates; unresolved issues cover the whole store. SLA estimates start at order creation in the shop timezone.') }}</p>
        </x-slot:form>
        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="This snapshot is incomplete. Review source coverage before treating zero findings as clear." :pages="$result->pages">
                <x-slot:heading>{{ __('Snapshot checked at :time (:timezone)', ['time' => \App\Support\UiFormat::date($result->meta['checkedAt'], true, $result->meta['timezone']), 'timezone' => $result->meta['timezone']]) }}</x-slot:heading>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    @foreach (['paid_pending' => 'Paid / Shopify pending', 'sync_findings' => 'Sync findings', 'sla_overdue' => 'Estimated SLA overdue', 'sla_due_soon' => 'Estimated SLA due within 24 hours', 'open_issues' => 'Unresolved issues'] as $key => $label)
                        @php($count = $result->meta['counts'][$key])
                        @php($partial = $key !== 'open_issues' && ($result->meta['coverage']['shopify'] !== 'complete' || ($key === 'sync_findings' && $result->meta['coverage']['shipstation'] !== 'complete')))
                        <x-stat-tile :label="$label" :value="$count === null ? __('Unavailable') : (($partial ? '≥ ' : '').$count)" />
                    @endforeach
                </div>
                <x-card>
                    <h2 class="text-lg font-semibold">{{ __('Source coverage') }}</h2>
                    <p class="mt-2 text-sm">{{ __('Shopify coverage: :shopify; ShipStation coverage: :shipstation', ['shopify' => __($result->meta['coverage']['shopify']), 'shipstation' => __($result->meta['coverage']['shipstation'])]) }}</p>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('ShipStation reconciliation requires credentials and its store number. Records without store identity are excluded.') }}</p>
                    @if (isset($result->meta['coverage']['older_orders']))<p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __($result->meta['coverage']['older_orders']) }}</p>@endif
                    @foreach (['manifests', 'billing_refunds', 'handover'] as $key)<p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __($result->meta['coverage'][$key]) }}</p>@endforeach
                </x-card>
                <x-data-table :headers="['Type', 'Reference', 'Finding', 'Due', 'Action']" :rows="$result->rows" empty="No findings in the available data.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">{{ __($row['kind']) }}</td>
                            <td class="px-4 py-3 font-semibold">{{ $row['order_number'] ?: $row['reference'] }}</td>
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::text($row['description']) }}</td>
                            <td class="px-4 py-3">{{ \App\Support\UiFormat::date($row['due_at'], $row['kind'] !== 'open_issue', $row['kind'] === 'open_issue' ? null : $result->meta['timezone']) }}</td>
                            <td class="px-4 py-3">@if (isset($row['issue_id']))<x-button size="sm" variant="ghost" :href="route('operational-issues.index').'#issue-'.$row['issue_id']">{{ __('Review issue') }}</x-button>@elseif ($row['order_number'] !== '')<x-button size="sm" variant="ghost" :href="route('orders.timeline', ['order_number' => $row['order_number']])">{{ __('Review order') }}</x-button>@endif</td>
                        </tr>
                    @endforeach
                </x-data-table>
                @can('manage-administration')<x-button size="sm" variant="ghost" :href="route('admin.email-rules.edit')">{{ __('Configure digest email') }}</x-button>@endcan
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
