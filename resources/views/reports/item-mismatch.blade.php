@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Fulfillment report" title="Shipped Item Mismatch" subtitle="ShipStation shipped SKU quantities compared with Shopify ordered quantities." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.item-mismatch.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end">
                <x-button type="submit">Run report</x-button>
            </div>
        </form>

        @error('export')
            <x-alert tone="error">{{ $message }}</x-alert>
        @enderror
        @if ($configurationError)
            <x-alert tone="warn">Shopify and ShipStation credentials are required for the active store.</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">The report could not be completed. Check both integrations and try again.</x-alert>
        @endif

        @if ($result)
            <div class="flex justify-between gap-3">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} ShipStation orders scanned · {{ count($result->rows) }} mismatches</h2>
                <form method="POST" action="{{ route('reports.item-mismatch.export') }}">
                    @csrf
                    <input type="hidden" name="start_date" value="{{ $result->startDate }}">
                    <input type="hidden" name="end_date" value="{{ $result->endDate }}">
                    <x-button type="submit" variant="ghost">Download CSV</x-button>
                </form>
            </div>
            @if ($result->shopifyTruncated)
                <x-alert tone="warn">Results are incomplete: Shopify orders were truncated after {{ $result->shopifyPages }} pages.</x-alert>
            @endif

            <x-data-table :headers="['Order', 'Date', 'Email', 'Type', 'Missing', 'Extra', 'Missing required', 'Total']">
                @forelse ($result->rows as $row)
                    <tr>
                        <td class="px-4 py-3 font-semibold">{{ $row['order_number'] }}</td>
                        <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                        <td class="px-4 py-3">{{ $row['email'] }}</td>
                        <td class="px-4 py-3">{{ $row['order_type'] }}</td>
                        <td class="px-4 py-3">
                            @foreach ($row['missing'] as $sku => $qty)
                                <div>{{ $sku }} ×{{ $qty }}</div>
                            @endforeach
                        </td>
                        <td class="px-4 py-3">
                            @foreach ($row['extra'] as $sku => $qty)
                                <div>{{ $sku }} ×{{ $qty }}</div>
                            @endforeach
                        </td>
                        <td class="px-4 py-3">
                            @foreach ($row['missing_required'] as $label)
                                <div>{{ $label }}</div>
                            @endforeach
                        </td>
                        <td class="px-4 py-3">{{ number_format($row['total'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="8">Everything shipped matches what was ordered.</td>
                    </tr>
                @endforelse
            </x-data-table>
        @endif
    </div>
@endsection
