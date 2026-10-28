@extends('layouts.app')

@section('content')
    @php
        $stateLabels = ['within_grace' => __('Within grace window'), 'beyond_grace' => __('Beyond grace window')];
        $refreshLabels = ['not_configured' => __('ShipStation is not configured completely for this store.'), 'unavailable' => __('ShipStation did not return a refresh status for this store or account.')];
    @endphp
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="{{ __('Import freshness') }}" subtitle="{{ __('Orders that Shopify has but ShipStation does not, aged across audit runs. Read-only; no store is refreshed or reconnected.') }}">
            <x-button size="sm" variant="ghost" :href="route('operational-issues.index')">{{ __('Issue triage') }}</x-button>
            <x-button size="sm" :href="route('reports.run-audit')">{{ __('Run audit') }}</x-button>
        </x-page-header>

        <x-alert tone="warn">{{ __('Age is counted from the first audit that found the order absent. These are observation intervals between audits, not exact import durations. Held, ignored, cancelled and test orders are excluded by the audit, and a failed or incomplete audit neither resets an age nor marks an order recovered.') }}</x-alert>

        <x-card>
            <h2 class="mb-3 text-xl font-bold">{{ __('Grace window') }}</h2>
            <form class="grid gap-4 sm:grid-cols-[8rem_auto] sm:items-end" method="POST" action="{{ route('operational-issues.import-freshness.update') }}">
                @csrf @method('PUT')
                <div>
                    <label class="text-sm font-medium" for="import_grace_hours">{{ __('Hours') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="import_grace_hours" name="import_grace_hours" type="number" min="1" max="720" required value="{{ old('import_grace_hours', $grace_hours) }}">
                    @error('import_grace_hours')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <x-button type="submit">{{ __('Save grace window') }}</x-button>
            </form>
            <p class="mt-3 text-sm">{{ __('How long an expected order may be absent from ShipStation before it counts as late. Late orders raise one alert per store while they remain.') }}</p>
        </x-card>

        <x-card>
            <h2 class="mb-3 text-xl font-bold">{{ __('Latest audit') }}</h2>
            @if ($last_run === null)
                <p>{{ __('No audit has been run for this store yet.') }}</p>
            @else
                <p>{{ __('Last run') }}: {{ \App\Support\UiFormat::date($last_run['at']->toIso8601String(), true) }} · {{ __($last_run['status']) }}</p>
                <p>{{ __('Last successful run') }}: {{ $last_success_at ? \App\Support\UiFormat::date($last_success_at->toIso8601String(), true) : '—' }}</p>
                @if ($last_run['status'] === 'error')<x-alert tone="error" class="mt-3">{{ __('The latest audit failed, so the ages below come from earlier runs.') }}</x-alert>@endif
            @endif
            <form class="mt-4" method="GET" action="{{ route('operational-issues.import-freshness') }}">
                <input type="hidden" name="status" value="1">
                <x-button type="submit" variant="ghost">{{ __('Read ShipStation refresh status') }}</x-button>
            </form>
            @if ($refresh)
                <div class="mt-3 text-sm">
                    @if ($refresh['state'] === 'reported')
                        <p>{{ __('ShipStation reports') }}: {{ $refresh['status'] ?? '—' }} · {{ $refresh['refresh_date'] ?? '—' }}</p>
                        <p>{{ __('This is shown as reported. A refresh date is not proof that the import completed, and it is not used to judge the connection.') }}</p>
                    @else
                        <p>{{ $refreshLabels[$refresh['state']] }}</p>
                    @endif
                </div>
            @endif
        </x-card>

        @if ($freshness['rows'] === [])
            <x-empty-state title="{{ __('No expected orders are currently absent from ShipStation') }}" />
        @else
            @if ($freshness['stale'])<x-alert tone="error">{{ trans_choice('{1} :count expected order has been absent longer than :hours hours.|[2,*] :count expected orders have been absent longer than :hours hours.', $freshness['beyond_grace'], ['hours' => $grace_hours]) }}</x-alert>@endif
            <x-card>
                <table class="w-full border-collapse text-sm">
                    <thead><tr class="border-b"><th class="py-2 pr-3 text-left">{{ __('Order') }}</th><th class="py-2 pr-3 text-left">{{ __('Result') }}</th><th class="py-2 pr-3 text-right">{{ __('Hours absent') }}</th><th class="py-2 pr-3 text-right">{{ __('Audits that found it absent') }}</th><th class="py-2 pr-3 text-left">{{ __('First found absent') }}</th><th class="py-2 text-left">{{ __('Last found absent') }}</th></tr></thead>
                    <tbody>
                        @foreach ($freshness['rows'] as $row)
                            <tr class="border-b align-top">
                                <td class="py-3 pr-3 font-semibold">{{ $row['reference'] }}</td>
                                <td class="py-3 pr-3"><x-badge :tone="$row['state'] === 'beyond_grace' ? 'danger' : 'warn'">{{ $stateLabels[$row['state']] }}</x-badge></td>
                                <td class="py-3 pr-3 text-right">{{ $row['age_hours'] }}</td>
                                <td class="py-3 pr-3 text-right">{{ $row['occurrences'] }}</td>
                                <td class="py-3 pr-3">{{ \App\Support\UiFormat::date($row['first_seen_at']->toIso8601String(), true) }}</td>
                                <td class="py-3">{{ \App\Support\UiFormat::date($row['last_seen_at']->toIso8601String(), true) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif
    </div>
@endsection
