@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="Run audit" subtitle="Find active Shopify orders missing from ShipStation." />

        @if (session('status'))
            <x-alert tone="ok">{{ session('status') }}</x-alert>
        @endif
        @error('queue')
            <x-alert tone="error">{{ $message }}</x-alert>
        @enderror

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-900" method="POST">
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
            <div class="flex items-end gap-2">
                <x-button type="submit" formaction="{{ route('reports.run-audit.store') }}">Run now</x-button>
                <x-button type="submit" variant="ghost" formaction="{{ route('reports.run-audit.queue') }}">Queue</x-button>
            </div>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">Shopify and ShipStation credentials are required for the active store.</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">The audit could not be completed. Check both integrations and try again.</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->shopifyTotal }} Shopify orders · {{ count($result->missing) }} missing</h2>

                <div class="grid gap-3 sm:grid-cols-4">
                    <x-card padding="p-4">Found: {{ $result->found }}</x-card>
                    <x-card padding="p-4">Skipped: {{ $result->skipped }}</x-card>
                    <x-card padding="p-4">Ignored: {{ $result->ignored }}</x-card>
                    <x-card padding="p-4">ShipStation: {{ $result->shipstationTotal }}</x-card>
                </div>

                @if ($result->shopifyTruncated)
                    <x-alert tone="warn">Results are incomplete because Shopify data was truncated.</x-alert>
                @endif

                @if ($result->duplicates !== [])
                    <x-alert tone="warn">
                        <strong>{{ count($result->duplicates) }} potential duplicate{{ count($result->duplicates) === 1 ? '' : 's' }} detected</strong> — same email and amount within 24 hours.
                        <ul class="mt-2 list-disc pl-5">
                            @foreach ($result->duplicates as $cluster)
                                <li>{{ $cluster['email'] }} · {{ number_format((float) $cluster['amount'], 2) }} — {{ implode(', ', array_map(fn ($order) => $order['name'] ?? $order['order_number'] ?? '?', $cluster['orders'])) }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif

                @if ($result->missing !== [])
                    @include('partials.bulk-ignore-form')
                @endif

                <x-data-table :headers="['', 'Order', 'Date', 'Email', 'Total']">
                    @forelse ($result->missing as $order)
                        @php($number = $order['name'] ?? $order['order_number'] ?? '')
                        <tr>
                            <td class="px-4 py-3"><input type="checkbox" name="order_numbers[]" value="{{ $number }}" form="bulk-ignore"></td>
                            <td class="px-4 py-3 font-semibold">{{ $number ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $order['created_at'] ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $order['email'] ?? '—' }}</td>
                            <td class="px-4 py-3">{{ number_format((float) ($order['total_price'] ?? 0), 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="5">Every eligible Shopify order was found in ShipStation.</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
