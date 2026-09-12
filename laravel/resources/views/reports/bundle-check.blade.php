@extends('layouts.app')

@section('content')
<div class="flex flex-col gap-6">
    <section><p class="text-sm font-medium text-indigo-600">Order audit</p><h1 class="text-3xl font-bold">Bundle Check</h1><p class="text-slate-500">{{ $description }}</p>@if($ruleNames)<p class="text-sm text-slate-500">Active rules: {{ implode(', ', $ruleNames) }}</p>@endif</section>
    <form class="grid gap-4 rounded-xl border p-5 sm:grid-cols-3" method="POST" action="{{ route('reports.bundle-check.store') }}">@csrf
        @foreach(['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])<div><label for="{{ $field }}">{{ $label }}</label><input class="w-full rounded-lg border px-3 py-2" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">@error($field)<p class="text-red-600">{{ $message }}</p>@enderror</div>@endforeach
        <button class="rounded-lg bg-indigo-600 px-5 py-2 text-white">Run report</button>
    </form>
    @error('export')<div class="rounded-xl bg-red-50 p-4">{{ $message }}</div>@enderror
    @if($configurationError)<div class="rounded-xl bg-amber-50 p-4">Shopify credentials are incomplete for the active store.</div>@endif
    @if($reportFailed)<div class="rounded-xl bg-red-50 p-4">The report could not be completed. Check Shopify and try again.</div>@endif
    @if($result)
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-2xl font-bold">{{ $result->scanned }} orders scanned · {{ count($result->rows) }} incomplete bundles</h2><form method="POST" action="{{ route('reports.bundle-check.export') }}">@csrf<input type="hidden" name="start_date" value="{{ $result->startDate }}"><input type="hidden" name="end_date" value="{{ $result->endDate }}"><button class="rounded-lg border px-4 py-2">Download CSV</button></form></div>
        @if($result->truncated)<div class="rounded-xl bg-amber-50 p-4">Results are incomplete: Shopify orders were truncated after {{ $result->pages }} pages.</div>@endif
        <div class="overflow-x-auto rounded-xl border"><table class="min-w-full text-left"><thead><tr><th class="p-3">Order</th><th>Date</th><th>Type</th><th>Missing items</th><th>Fulfillment</th><th>Financial</th><th>Email</th></tr></thead><tbody>@forelse($result->rows as $row)<tr><td class="p-3 font-semibold">{{ $row['order_number'] }}</td><td>{{ $row['created_at'] }}</td><td>{{ $row['order_type'] }}</td><td>{{ $row['missing_text'] }}</td><td>{{ $row['fulfillment_status'] ?: 'unfulfilled' }}</td><td>{{ $row['financial_status'] }}</td><td>{{ $row['email'] ?: '—' }}</td></tr>@empty<tr><td class="p-6 text-center" colspan="7">All bundles are complete.</td></tr>@endforelse</tbody></table></div>
    @endif
</div>
@endsection
