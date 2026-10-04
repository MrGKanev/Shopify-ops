@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Gift card report" title="Gift cards" subtitle="Find enabled cards with remaining balances that expire soon or have never been redeemed." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-card>
                <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>{{ __('Disabled and fully redeemed cards are excluded.') }}</li>
                    <li>{{ __('A card may be both expiring soon and never redeemed.') }}</li>
                    <li>{{ __('Results are sorted by remaining balance.') }}</li>
                </ul>
            </x-card>

            <x-report.params-form
                :action="route('reports.gift-cards.store')"
                :fields="['days' => ['label' => 'Expiring within (days)', 'type' => 'number', 'value' => $days, 'min' => 1, 'max' => 3650, 'step' => 1]]"
                submit-label="Scan gift cards"
            />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results were truncated after :pages gift card pages." :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} gift cards · {{ count($result->rows) }} flagged</x-slot:heading>

                <x-data-table :headers="['Code', 'Customer', 'Balance', 'Initial value', 'Expires', 'Issues']" :rows="$result->rows" empty="All scanned gift cards are fresh, redeemed, disabled, or not close to expiry.">
                    @foreach ($result->rows as $row)
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
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
