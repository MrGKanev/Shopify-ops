@extends('layouts.app')

@section('content')
<div class="flex flex-col gap-6">
    <section><p class="text-sm font-medium text-indigo-600">Fulfillment report</p><h1 class="text-3xl font-bold">Fulfillment SLA Breaches</h1><p class="text-slate-500">Paid orders exceeding the configured time to first fulfillment.</p></section>
    <form class="grid gap-4 rounded-xl border p-5 sm:grid-cols-4" method="POST" action="{{ route('reports.fulfillment-sla.store') }}">@csrf
        @foreach(['start_date' => ['From', $startDate, 'date'], 'end_date' => ['To', $endDate, 'date'], 'threshold' => ['SLA days', $threshold, 'number']] as $field => [$label, $value, $type])<div><label for="{{ $field }}">{{ $label }}</label><input class="w-full rounded-lg border px-3 py-2" id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" @if($type === 'number') min="1" max="365" @endif value="{{ old($field, $value) }}">@error($field)<p class="text-red-600">{{ $message }}</p>@enderror</div>@endforeach
        <button class="rounded-lg bg-indigo-600 px-5 py-2 text-white">Run report</button>
    </form>
    @error('export')<div class="rounded-xl bg-red-50 p-4">{{ $message }}</div>@enderror
    @if($configurationError)<div class="rounded-xl bg-amber-50 p-4">Shopify credentials are incomplete for the active store.</div>@endif
    @if($reportFailed)<div class="rounded-xl bg-red-50 p-4">The report could not be completed. Check Shopify and try again.</div>@endif
    @if($result)
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-2xl font-bold">{{ $result->scanned }} orders scanned · {{ count($result->rows) }} breaches of {{ $result->threshold }} days</h2><form method="POST" action="{{ route('reports.fulfillment-sla.export') }}">@csrf<input type="hidden" name="start_date" value="{{ $result->startDate }}"><input type="hidden" name="end_date" value="{{ $result->endDate }}"><input type="hidden" name="threshold" value="{{ $result->threshold }}"><button class="rounded-lg border px-4 py-2">Download CSV</button></form></div>
        @if($result->truncated)<div class="rounded-xl bg-amber-50 p-4">Results are incomplete: Shopify orders were truncated after {{ $result->pages }} pages.</div>@endif
        <div class="overflow-x-auto rounded-xl border"><table class="min-w-full text-left"><thead><tr><th class="p-3">Order</th><th>Placed</th><th>Fulfilled</th><th>Days</th><th>Method</th><th>Region</th><th>Type</th><th>Status</th></tr></thead><tbody>@forelse($result->rows as $row)<tr><td class="p-3 font-semibold">{{ $row['order_number'] }}</td><td>{{ $row['created_at'] }}</td><td>{{ $row['fulfilled_at'] ?: '—' }}</td><td>{{ $row['days'] }}</td><td>{{ $row['method'] }}</td><td>{{ $row['region'] }}</td><td>{{ $row['order_type'] }}</td><td>{{ $row['financial'] }} {{ $row['fulfillment'] }}</td></tr>@empty<tr><td class="p-6 text-center" colspan="8">No SLA breaches found.</td></tr>@endforelse</tbody></table></div>
    @endif
</div>
@endsection
