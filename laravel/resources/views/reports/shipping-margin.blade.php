@extends('layouts.app')

@section('content')
<div class="flex flex-col gap-6">
    <section><p class="text-sm font-medium text-indigo-600">Fulfillment report</p><h1 class="text-3xl font-bold">Shipping Margin Erosion</h1><p class="text-slate-500">ShipStation label cost compared with shipping charged in Shopify.</p></section>
    <form class="grid gap-4 rounded-xl border p-5 sm:grid-cols-4" method="POST" action="{{ route('reports.shipping-margin.store') }}">
        @csrf
        @foreach(['start_date' => ['From', $startDate, 'date'], 'end_date' => ['To', $endDate, 'date'], 'threshold' => ['Loss threshold', $threshold, 'number']] as $field => [$label, $value, $type])<div><label for="{{ $field }}">{{ $label }}</label><input class="w-full rounded-lg border px-3 py-2" id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" min="{{ $type === 'number' ? 1 : '' }}" step="{{ $type === 'number' ? '0.01' : '' }}" value="{{ old($field, $value) }}">@error($field)<p class="text-red-600">{{ $message }}</p>@enderror</div>@endforeach
        <button class="rounded-lg bg-indigo-600 px-5 py-2 text-white">Run report</button>
    </form>
    @error('export')<div class="rounded-xl bg-red-50 p-4">{{ $message }}</div>@enderror
    @if($configurationError)<div class="rounded-xl bg-amber-50 p-4">Shopify or ShipStation credentials are incomplete for the active store.</div>@endif
    @if($reportFailed)<div class="rounded-xl bg-red-50 p-4">The report could not be completed. Check Shopify and ShipStation, then try again.</div>@endif
    @if($result)
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-2xl font-bold">{{ $result->scanned }} shipments scanned · {{ count($result->rows) }} losses over ${{ number_format($result->threshold, 2) }}</h2><form method="POST" action="{{ route('reports.shipping-margin.export') }}">@csrf<input type="hidden" name="start_date" value="{{ $result->startDate }}"><input type="hidden" name="end_date" value="{{ $result->endDate }}"><input type="hidden" name="threshold" value="{{ $result->threshold }}"><button class="rounded-lg border px-4 py-2">Download CSV</button></form></div>
        @if($result->shopifyTruncated)<div class="rounded-xl bg-amber-50 p-4">Results are incomplete: Shopify orders were truncated after {{ $result->shopifyPages }} pages.</div>@endif
        @if($result->byCarrier)<div class="overflow-x-auto rounded-xl border"><table class="min-w-full text-left"><thead><tr><th class="p-3">Carrier</th><th>Orders</th><th>Total loss</th><th>Average loss</th></tr></thead><tbody>@foreach($result->byCarrier as $row)<tr><td class="p-3 font-semibold">{{ $row['carrier'] }}</td><td>{{ $row['count'] }}</td><td>${{ number_format($row['total_loss'], 2) }}</td><td>${{ number_format($row['avg_loss'], 2) }}</td></tr>@endforeach</tbody></table></div>@endif
        <div class="overflow-x-auto rounded-xl border"><table class="min-w-full text-left"><thead><tr><th class="p-3">Order</th><th>Ship date</th><th>Carrier / service</th><th>Ship cost</th><th>Charged</th><th>Loss</th><th>Email</th><th>Total</th></tr></thead><tbody>@forelse($result->rows as $row)<tr><td class="p-3 font-semibold">{{ $row['order_number'] }}</td><td>{{ $row['ship_date'] }}</td><td>{{ $row['carrier'] }}{{ $row['service'] ? ' / '.$row['service'] : '' }}</td><td>${{ number_format($row['ship_cost'], 2) }}</td><td>${{ number_format($row['shipping_charged'], 2) }}</td><td class="font-semibold text-red-600">${{ number_format($row['loss'], 2) }}</td><td>{{ $row['email'] ?: '—' }}</td><td>${{ number_format($row['total'], 2) }}</td></tr>@empty<tr><td class="p-6 text-center" colspan="8">No margin-eroding shipments found.</td></tr>@endforelse</tbody></table></div>
    @endif
</div>
@endsection
