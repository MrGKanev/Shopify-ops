@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Fulfillment report" title="Partial Fulfillment Stalls" subtitle="Open partially fulfilled orders whose remaining items have stopped progressing." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.partial-fulfillment.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate, 'date'], 'end_date' => ['To', $endDate, 'date'], 'threshold' => ['Stalled days', $threshold, 'number']] as $field => [$label, $value, $type])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" @if($type === 'number') min="1" max="365" @endif value="{{ old($field, $value) }}">
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
            <x-alert tone="warn">Shopify credentials are incomplete for the active store.</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">The report could not be completed. Check Shopify and try again.</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-2xl font-bold">{{ $result->scanned }} partial orders scanned · {{ count($result->rows) }} stalled ≥ {{ $result->threshold }} days</h2>
                    <form method="POST" action="{{ route('reports.partial-fulfillment.export') }}">
                        @csrf
                        <input type="hidden" name="start_date" value="{{ $result->startDate }}">
                        <input type="hidden" name="end_date" value="{{ $result->endDate }}">
                        <input type="hidden" name="threshold" value="{{ $result->threshold }}">
                        <x-button type="submit" variant="ghost">Download CSV</x-button>
                    </form>
                </div>

                @if ($result->truncated)
                    <x-alert tone="warn">Results are incomplete: Shopify orders were truncated after {{ $result->pages }} pages.</x-alert>
                @endif

                <x-data-table :headers="['Order', 'Placed', 'Last fulfillment', 'Stalled', 'Unfulfilled items', 'Email', 'Total']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $row['order_number'] }}</td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['last_fulfilled'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $row['days_stalled'] }} days</td>
                            <td class="px-4 py-3">
                                @foreach ($row['unfulfilled_items'] as $item)
                                    <div>{{ $item['name'] }} @if ($item['sku'])<span class="text-slate-500">· {{ $item['sku'] }}</span>@endif ×{{ $item['qty'] }}</div>
                                @endforeach
                            </td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['total_price'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="7">No stalled partial fulfillments found.</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
