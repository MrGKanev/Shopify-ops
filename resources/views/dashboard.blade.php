@extends('layouts.app')
@section('content')
<div class="flex flex-col gap-6">
    <x-page-header title="Dashboard" :subtitle="$activeStore->label.' · '.$activeStore->shopify_store.'.myshopify.com'">
        @can('run-audits')
            <x-button size="sm" :href="route('reports.run-audit')">Run Audit</x-button>
        @endcan
        @can('manage-administration')
            <form method="POST" action="{{ route('admin.cache.flush') }}">
                @csrf
                <x-button variant="ghost" size="sm" type="submit">Flush cache</x-button>
            </form>
        @endcan
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-tile label="Latest audit" :value="$latest?->rows_found ?? '—'" :tone="($latest?->rows_found ?? 0) > 0 ? 'warn' : 'ok'">
            <x-slot:sub>
                @if ($latest)
                    missing · {{ $latest->report_date->toDateString() }}
                    @if ($previousMissing !== null) · was {{ $previousMissing }} @endif
                @else
                    No audits yet
                @endif
            </x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="All-time audits" :value="$totalReports">
            <x-slot:sub>{{ $totalMissing }} total missing</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Pushes" :value="$pushesToday">
            <x-slot:sub>today · {{ $pushesMonth }} in 30 days</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Ignored orders" :value="$ignoredCount" :tone="$ignoredCount > 0 ? 'warn' : 'default'">
            <x-slot:sub><a href="{{ route('ignored-orders.index') }}" class="text-indigo-600 dark:text-indigo-400">Manage ignored orders</a> · {{ $staleIgnoredCount }} stale (30d+)</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Audit cadence" :value="$auditCadenceDays ?? '—'">
            <x-slot:sub>avg days between audits</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Avg resolution" :value="$avgResolutionDays ?? '—'">
            <x-slot:sub>days to clear a missing order</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Oldest missing" :value="$oldestMissingAge ?? '—'" :tone="($oldestMissingAge ?? 0) > 7 ? 'warn' : 'default'">
            <x-slot:sub>days since placed</x-slot:sub>
        </x-stat-tile>
    </div>

    @if ($sevenDayChart->isNotEmpty())
        <x-card>
            <div class="mb-3 text-sm font-semibold">Last {{ $sevenDayChart->count() }} audits</div>
            <div class="flex items-end gap-2" style="height:80px">
                @php($max = max(1, $sevenDayChart->max('missing')))
                @foreach ($sevenDayChart as $point)
                    <div class="flex flex-col items-center gap-1" title="{{ $point['date'] }}: {{ $point['missing'] }} missing">
                        <div style="width:24px;height:{{ max(4, (int) ($point['missing'] / $max * 70)) }}px;background:#6366f1;border-radius:4px 4px 0 0"></div>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ substr($point['date'], 5) }}</span>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    @if ($missingByType !== [])
        <x-card>
            <div class="mb-3 text-sm font-semibold">Missing by type</div>
            <ul class="flex flex-wrap gap-3 text-sm text-slate-600 dark:text-slate-300">
                @foreach ($missingByType as $type => $count)
                    <li class="rounded-lg bg-slate-100 px-3 py-1.5 dark:bg-slate-800">{{ $type }}: {{ $count }}</li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <section class="flex flex-col gap-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold">Action queue</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">Missing orders from the latest audit.</p>
            </div>
            @if ($latest)
                <a class="text-sm font-medium text-indigo-600 dark:text-indigo-400" href="{{ route('saved-reports.show', $latest) }}">Full snapshot</a>
            @endif
        </div>
        @if (! $latest)
            <x-empty-state icon="📋" title="No audits yet">Run the first audit to build the queue.</x-empty-state>
        @elseif ($missingOrders === [])
            <x-empty-state icon="✓" title="All clear">No missing orders.</x-empty-state>
        @else
            <x-data-table :headers="['Order', 'Date', 'Email', 'Total']">
                @foreach ($missingOrders as $order)
                    <tr>
                        <td class="px-4 py-3 font-semibold">{{ $order['name'] ?? $order['order_number'] ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $order['created_at'] ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $order['email'] ?? '—' }}</td>
                        <td class="px-4 py-3">{{ number_format((float) ($order['total_price'] ?? 0), 2) }}</td>
                    </tr>
                @endforeach
            </x-data-table>
        @endif
    </section>

    @can('run-audits')
        <x-card>
            <div class="mb-3 flex items-center justify-between">
                <div class="text-sm font-semibold">Quick actions</div>
                <a class="text-sm font-medium text-indigo-600 dark:text-indigo-400" href="{{ route('audits.index') }}">Browse all audits →</a>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ([['📋', 'Run Audit', 'reports.run-audit'], ['🏠', 'Address Check', 'reports.address-check'], ['✉', 'Email Check', 'reports.email-check'], ['📦', 'Bundle Check', 'reports.bundle-check'], ['⏳', 'Partial Stalls', 'reports.partial-fulfillment'], ['🔎', 'Orphan Orders', 'reports.orphan-orders']] as [$icon, $label, $name])
                    <x-button variant="ghost" size="sm" :href="route($name)"><span>{{ $icon }}</span>{{ $label }}</x-button>
                @endforeach
            </div>
        </x-card>
    @endcan
</div>
@endsection
