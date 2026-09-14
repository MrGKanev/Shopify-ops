@extends('layouts.app')

@section('content')
<div class="flex flex-col gap-6">
    <section><p class="text-sm font-medium text-indigo-600">Fulfillment audit</p><h1 class="text-3xl font-bold">Carrier Performance</h1><p class="text-slate-500">Average delivery time and late-delivery rate grouped by ShipStation carrier.</p></section>
    <form class="grid gap-4 rounded-xl border p-5 sm:grid-cols-3" method="POST" action="{{ route('reports.carrier-performance.store') }}">
        @csrf
        @foreach(['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])<div><label for="{{ $field }}">{{ $label }}</label><input class="w-full rounded-lg border px-3 py-2" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">@error($field)<p class="text-red-600">{{ $message }}</p>@enderror</div>@endforeach
        <button class="rounded-lg bg-indigo-600 px-5 py-2 text-white">Run audit</button>
    </form>
    @if($configurationError)<div class="rounded-xl bg-amber-50 p-4">ShipStation credentials are incomplete for the active store.</div>@endif
    @if($reportFailed)<div class="rounded-xl bg-red-50 p-4">The audit could not be completed. Check ShipStation and try again.</div>@endif
    @if($result)
        <h2 class="text-2xl font-bold">{{ $result->scanned }} shipments · {{ count($result->rows) }} carriers</h2>
        <div class="overflow-x-auto rounded-xl border"><table class="min-w-full text-left"><thead><tr><th class="p-3">Carrier</th><th>Shipments</th><th>With delivery date</th><th>Avg delivery days</th><th>Late deliveries</th><th>Late %</th></tr></thead><tbody>@forelse($result->rows as $row)<tr><td class="p-3 font-semibold">{{ $row['carrier'] }}</td><td>{{ $row['count'] }}</td><td>{{ $row['with_delivery'] }}</td><td>{{ $row['avg_days'] === null ? '—' : $row['avg_days'].' days' }}</td><td>{{ $row['late_count'] }}</td><td>{{ $row['late_pct'] === null ? '—' : $row['late_pct'].'%' }}</td></tr>@empty<tr><td class="p-6 text-center" colspan="6">No shipments found.</td></tr>@endforelse</tbody></table></div>
    @endif
</div>
@endsection
