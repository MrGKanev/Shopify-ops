@extends('layouts.app')

@section('content')
<div class="flex flex-col gap-6">
    <section><p class="text-sm font-medium text-indigo-600">Fulfillment report</p><h1 class="text-3xl font-bold">Partial Fulfillment Stalls</h1><p class="text-slate-500">Open partially fulfilled orders whose remaining items have stopped progressing.</p></section>
    <form class="grid gap-4 rounded-xl border p-5 sm:grid-cols-4" method="POST" action="{{ route('reports.partial-fulfillment.store') }}">@csrf
        @foreach(['start_date' => ['From', $startDate, 'date'], 'end_date' => ['To', $endDate, 'date'], 'threshold' => ['Stalled days', $threshold, 'number']] as $field => [$label, $value, $type])<div><label for="{{ $field }}">{{ $label }}</label><input class="w-full rounded-lg border px-3 py-2" id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" @if($type === 'number') min="1" max="365" @endif value="{{ old($field, $value) }}">@error($field)<p class="text-red-600">{{ $message }}</p>@enderror</div>@endforeach
        <button class="rounded-lg bg-indigo-600 px-5 py-2 text-white">Run report</button>
    </form>
    @error('export')<div class="rounded-xl bg-red-50 p-4">{{ $message }}</div>@enderror
    @if($configurationError)<div class="rounded-xl bg-amber-50 p-4">Shopify credentials are incomplete for the active store.</div>@endif
    @if($reportFailed)<div class="rounded-xl bg-red-50 p-4">The report could not be completed. Check Shopify and try again.</div>@endif
    @if($result)
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-2xl font-bold">{{ $result->scanned }} partial orders scanned · {{ count($result->rows) }} stalled ≥ {{ $result->threshold }} days</h2><form method="POST" action="{{ route('reports.partial-fulfillment.export') }}">@csrf<input type="hidden" name="start_date" value="{{ $result->startDate }}"><input type="hidden" name="end_date" value="{{ $result->endDate }}"><input type="hidden" name="threshold" value="{{ $result->threshold }}"><button class="rounded-lg border px-4 py-2">Download CSV</button></form></div>
        @if($result->truncated)<div class="rounded-xl bg-amber-50 p-4">Results are incomplete: Shopify orders were truncated after {{ $result->pages }} pages.</div>@endif
        <div class="overflow-x-auto rounded-xl border"><table class="min-w-full text-left"><thead><tr><th class="p-3">Order</th><th>Placed</th><th>Last fulfillment</th><th>Stalled</th><th>Unfulfilled items</th><th>Email</th><th>Total</th></tr></thead><tbody>@forelse($result->rows as $row)<tr><td class="p-3 font-semibold">{{ $row['order_number'] }}</td><td>{{ $row['created_at'] }}</td><td>{{ $row['last_fulfilled'] ?: '—' }}</td><td>{{ $row['days_stalled'] }} days</td><td>@foreach($row['unfulfilled_items'] as $item)<div>{{ $item['name'] }} @if($item['sku'])<span class="text-slate-500">· {{ $item['sku'] }}</span>@endif ×{{ $item['qty'] }}</div>@endforeach</td><td>{{ $row['email'] }}</td><td>{{ number_format($row['total_price'], 2) }}</td></tr>@empty<tr><td class="p-6 text-center" colspan="7">No stalled partial fulfillments found.</td></tr>@endforelse</tbody></table></div>
    @endif
</div>
@endsection
