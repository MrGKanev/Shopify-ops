@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Risk report" title="Chargebacks / Disputes" subtitle="Open Shopify Payments disputes sorted by response deadline." />

        <form method="POST" action="{{ route('reports.disputes.store') }}">
            @csrf
            <x-button type="submit">Scan disputes</x-button>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} open disputes</h2>

                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: disputes truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                <x-data-table :headers="['Order', 'Status', 'Reason', 'Amount', 'Initiated', 'Days until due']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">@if ($row['order_id'])<a class="text-indigo-600" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $row['order_id'] }}">{{ $row['order_name'] }}</a>@else{{ $row['order_name'] ?: '—' }}@endif</td>
                            <td class="px-4 py-3">{{ str_replace('_', ' ', $row['status']) }}</td>
                            <td class="px-4 py-3">{{ str_replace('_', ' ', $row['reason']) }}</td>
                            <td class="px-4 py-3">{{ number_format($row['amount'], 2) }} {{ $row['currency'] }}</td>
                            <td class="px-4 py-3">{{ substr($row['initiated_at'], 0, 10) }}</td>
                            <td class="px-4 py-3">{{ $row['days_until_due'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">{{ __('No open disputes need a response.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
