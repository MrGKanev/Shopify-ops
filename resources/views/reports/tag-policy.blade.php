@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Risk report" title="Tag Policy Audit" subtitle="Check paid orders against required and forbidden tag combinations." />

        @unless ($configured)
            <x-alert tone="warn">No tag policy is configured. Add required or forbidden rules in <code>config/tag-policy.php</code> to enable this audit.</x-alert>
        @endunless

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.tag-policy.store') }}">
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
            <div class="flex items-end"><x-button type="submit" :disabled="! $configured">Run report</x-button></div>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">Shopify credentials are incomplete for the active store.</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">The report could not be completed. Check Shopify and try again.</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} policy violations</h2>
                @if ($result->truncated)
                    <x-alert tone="warn">Results are incomplete: orders truncated after {{ $result->pages }} pages.</x-alert>
                @endif

                <x-data-table :headers="['Order', 'Placed', 'Violations', 'Tags', 'Email', 'Status']">
                    @forelse ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-semibold">@if ($row['shopify_id'])<a class="text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $row['shopify_id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['order_number'] }}</a>@else{{ $row['order_number'] }}@endif</td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">
                                @foreach ($row['violations'] as $violation)
                                    <div><span class="font-medium">{{ $violation['name'] }}</span><br><span class="text-slate-500 dark:text-slate-400">{{ $violation['detail'] }}</span></div>
                                @endforeach
                            </td>
                            <td class="px-4 py-3">{{ implode(', ', $row['tags']) }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ $row['financial'] ?: '—' }}@if ($row['fulfillment']) · {{ str_replace('_', ' ', $row['fulfillment']) }}@endif</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">No scanned orders violated the configured tag policy.</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
