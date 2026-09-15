@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Fulfillment report" title="Fulfilled Items Report" subtitle="Successfully fulfilled quantities grouped by product for the selected period." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.fulfilled-items.store') }}">
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
            <x-alert tone="warn">Shopify credentials are incomplete for the active store.</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">The report could not be completed. Check Shopify and try again.</x-alert>
        @endif

        @if ($result)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} orders scanned · {{ count($result->rows) }} products</h2>
                <form method="POST" action="{{ route('reports.fulfilled-items.export') }}">
                    @csrf
                    <input type="hidden" name="start_date" value="{{ $result->startDate }}">
                    <input type="hidden" name="end_date" value="{{ $result->endDate }}">
                    <x-button variant="ghost" type="submit">Download CSV</x-button>
                </form>
            </div>

            @if ($result->truncated)
                <x-alert tone="warn">Results are incomplete: Shopify orders were truncated after {{ $result->pages }} pages.</x-alert>
            @endif

            <x-data-table :headers="['Product', 'Quantity']">
                @forelse ($result->rows as $row)
                    <tr>
                        <td class="px-4 py-3 font-semibold">{{ $row['product'] }}</td>
                        <td class="px-4 py-3">{{ $row['quantity'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="2">No fulfilled items found.</td>
                    </tr>
                @endforelse
            </x-data-table>
        @endif
    </div>
@endsection
