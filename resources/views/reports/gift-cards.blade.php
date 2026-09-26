@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Gift card report" title="Gift cards" subtitle="Find enabled cards with remaining balances that expire soon or have never been redeemed." />

        <x-card>
            <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                <li>{{ __('Disabled and fully redeemed cards are excluded.') }}</li>
                <li>{{ __('A card may be both expiring soon and never redeemed.') }}</li>
                <li>{{ __('Results are sorted by remaining balance.') }}</li>
            </ul>
        </x-card>

        <form class="flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.gift-cards.store') }}">
            @csrf
            <div>
                <label class="text-sm font-medium" for="days">{{ __('Expiring within (days)') }}</label>
                <input class="mt-2 block w-40 rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="days" min="1" max="3650" name="days" step="1" type="number" value="{{ old('days', $days) }}">
                @error('days')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>
                @enderror
            </div>
            <x-button type="submit">Scan gift cards</x-button>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} gift cards · {{ count($result->rows) }} flagged</h2>

                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results were truncated after :pages gift card pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                <x-data-table :headers="['Code', 'Customer', 'Balance', 'Initial value', 'Expires', 'Issues']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-mono">{{ $row['masked_code'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $row['customer_email'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ number_format($row['balance'], 2) }} {{ $row['currency'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['initial_value'], 2) }} {{ $row['currency'] }}</td>
                            <td class="px-4 py-3">{{ $row['expires_on'] ?: 'No expiry' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-1">
                                    @foreach ($row['reasons'] as $reason)
                                        <x-badge tone="warn" class="w-fit">{{ $reason }}</x-badge>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">{{ __('All scanned gift cards are fresh, redeemed, disabled, or not close to expiry.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
