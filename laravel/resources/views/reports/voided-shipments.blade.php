@extends('layouts.app')

@section('content')
<div class="flex flex-col gap-6">
    <section><p class="text-sm font-medium text-indigo-600">Fulfillment report</p><h1 class="text-3xl font-bold">Voided Shipments</h1><p class="text-slate-500">ShipStation shipments voided in the selected period.</p></section>
    <form class="grid gap-4 rounded-xl border p-5 sm:grid-cols-3" method="POST" action="{{ route('reports.voided-shipments.store') }}">@csrf
        @foreach(['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])<div><label for="{{ $field }}">{{ $label }}</label><input class="w-full rounded-lg border px-3 py-2" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">@error($field)<p class="text-red-600">{{ $message }}</p>@enderror</div>@endforeach
        <button class="rounded-lg bg-indigo-600 px-5 py-2 text-white">Run report</button>
    </form>
    @error('export')<div class="rounded-xl bg-red-50 p-4">{{ $message }}</div>@enderror
    @if($configurationError)<div class="rounded-xl bg-amber-50 p-4">ShipStation credentials are incomplete for the active store.</div>@endif
    @if($reportFailed)<div class="rounded-xl bg-red-50 p-4">The report could not be completed. Check ShipStation and try again.</div>@endif
    @if($result)
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-2xl font-bold">{{ count($result->rows) }} voided shipments</h2><form method="POST" action="{{ route('reports.voided-shipments.export') }}">@csrf<input type="hidden" name="start_date" value="{{ $result->startDate }}"><input type="hidden" name="end_date" value="{{ $result->endDate }}"><button class="rounded-lg border px-4 py-2">Download CSV</button></form></div>
        <div class="overflow-x-auto rounded-xl border"><table class="min-w-full text-left"><thead><tr><th class="p-3">Order</th><th>Void date</th><th>Ship date</th><th>Carrier / service</th><th>Tracking</th><th>Ship to</th></tr></thead><tbody>@forelse($result->rows as $row)<tr><td class="p-3 font-semibold">{{ $row['order_number'] }}</td><td>{{ $row['void_date'] }}</td><td>{{ $row['ship_date'] ?: '—' }}</td><td>{{ strtoupper($row['carrier']) ?: '—' }}{{ $row['service'] ? ' / '.$row['service'] : '' }}</td><td>{{ $row['tracking'] ?: '—' }}</td><td><strong>{{ $row['ship_to_name'] }}</strong><br>{{ implode(', ', array_filter([$row['ship_to_city'], $row['ship_to_state'], $row['ship_to_zip'], $row['ship_to_country']])) }}</td></tr>@empty<tr><td class="p-6 text-center" colspan="6">No voided shipments found.</td></tr>@endforelse</tbody></table></div>
    @endif
</div>
@endsection
