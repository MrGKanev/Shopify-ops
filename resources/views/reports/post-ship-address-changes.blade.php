@extends('layouts.app')

@section('content')
<div class="flex flex-col gap-6">
    <section><p class="text-sm font-medium text-indigo-600">Fulfillment report</p><h1 class="text-3xl font-bold">Post-Ship Address Changes</h1><p class="text-slate-500">Shipping addresses edited after the first fulfillment was created.</p></section>
    <form class="grid gap-4 rounded-xl border p-5 sm:grid-cols-3" method="POST" action="{{ route('reports.post-ship-address-changes.store') }}">@csrf
        @foreach(['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])<div><label for="{{ $field }}">{{ $label }}</label><input class="w-full rounded-lg border px-3 py-2" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">@error($field)<p class="text-red-600">{{ $message }}</p>@enderror</div>@endforeach
        <button class="rounded-lg bg-indigo-600 px-5 py-2 text-white">Run report</button>
    </form>
    @error('export')<div class="rounded-xl bg-red-50 p-4">{{ $message }}</div>@enderror
    @if($configurationError)<div class="rounded-xl bg-amber-50 p-4">Shopify credentials are incomplete for the active store.</div>@endif
    @if($reportFailed)<div class="rounded-xl bg-red-50 p-4">The report could not be completed. Check Shopify and try again.</div>@endif
    @if($result)
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-2xl font-bold">{{ count($result->rows) }} post-ship changes</h2><form method="POST" action="{{ route('reports.post-ship-address-changes.export') }}">@csrf<input type="hidden" name="start_date" value="{{ $result->startDate }}"><input type="hidden" name="end_date" value="{{ $result->endDate }}"><button class="rounded-lg border px-4 py-2">Download CSV</button></form></div>
        @if($result->truncated)<div class="rounded-xl bg-amber-50 p-4">Results are incomplete: events were truncated after {{ $result->pages }} pages.</div>@endif
        <div class="overflow-x-auto rounded-xl border"><table class="min-w-full text-left"><thead><tr><th class="p-3">Order</th><th>Placed</th><th>First fulfillment</th><th>Changed</th><th>After shipment</th><th>Email</th><th>Current address</th><th>Total</th><th>Status</th></tr></thead><tbody>@forelse($result->rows as $row)<tr><td class="p-3 font-semibold">{{ $row['order_number'] }}</td><td>{{ $row['created_at'] }}</td><td>{{ $row['fulfillment_at'] }}</td><td>{{ $row['changed_at'] }}</td><td>{{ $row['mins_after_ship'] }} minutes</td><td>{{ $row['email'] }}</td><td><strong>{{ $row['addr_name'] }}</strong><br>{{ $row['addr_line'] }}</td><td>{{ number_format($row['total'], 2) }}</td><td>{{ $row['financial'] }} {{ $row['fulfillment'] }}</td></tr>@empty<tr><td class="p-6 text-center" colspan="9">No post-ship address changes found.</td></tr>@endforelse</tbody></table></div>
    @endif
</div>
@endsection
