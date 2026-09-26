@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="API Health" subtitle="Run lightweight live checks for the active store's Shopify and ShipStation connections." />

        <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('admin.api-health.check') }}">
            @csrf
            <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">{{ __('The check reads one small response from each provider. It does not change orders or settings.') }}</p>
            <x-button type="submit">Run health check</x-button>
        </form>

        <x-card>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold">{{ __('SMTP delivery') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Mailer: {{ $mailConfiguration['mailer'] ?: 'not set' }} · From: {{ $mailConfiguration['from_address'] ?: 'invalid' }}</p>
                </div>
                <x-badge :tone="$mailConfiguration['configured'] ? 'ok' : 'warn'">{{ $mailConfiguration['configured'] ? 'Configured' : 'Needs configuration' }}</x-badge>
            </div>

            @if ($mailResult === 'sent')<x-alert class="mt-4" tone="ok">{{ __('Test email sent successfully.') }}</x-alert>@endif
            @if ($mailResult === 'failed')<x-alert class="mt-4" tone="error">{{ __('Test email could not be sent. Check the SMTP configuration and application log.') }}</x-alert>@endif

            <form class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end" method="POST" action="{{ route('admin.api-health.test-email') }}">
                @csrf
                <div class="flex-1">
                    <label class="text-sm font-medium" for="email">{{ __('Recipient') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="email" name="email" type="email" value="{{ old('email', auth()->user()->email) }}" maxlength="255" required>
                    @error('email')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>@enderror
                </div>
                <x-button type="submit" :disabled="! $mailConfiguration['configured']">Send test email</x-button>
            </form>
        </x-card>

        <x-card>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold">{{ __('Slack delivery') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Incoming webhook: {{ $slackConfiguration['endpoint'] ?: 'not configured' }}</p>
                </div>
                <x-badge :tone="$slackConfiguration['configured'] ? 'ok' : 'warn'">{{ $slackConfiguration['configured'] ? 'Configured' : 'Needs configuration' }}</x-badge>
            </div>

            @if ($slackResult === 'sent')<x-alert class="mt-4" tone="ok">{{ __('Test Slack notification sent successfully.') }}</x-alert>@endif
            @if ($slackResult === 'failed')<x-alert class="mt-4" tone="error">{{ __('Test Slack notification could not be sent. Check the webhook configuration and application log.') }}</x-alert>@endif

            <form class="mt-4" method="POST" action="{{ route('admin.api-health.test-slack') }}">
                @csrf
                <x-button type="submit" :disabled="! $slackConfiguration['configured']">Send test notification</x-button>
            </form>
        </x-card>

        <x-card>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold">{{ __('Discord delivery') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Incoming webhook: {{ $discordConfiguration['endpoint'] ?: 'not configured' }}</p>
                </div>
                <x-badge :tone="$discordConfiguration['configured'] ? 'ok' : 'warn'">{{ $discordConfiguration['configured'] ? 'Configured' : 'Needs configuration' }}</x-badge>
            </div>

            @if ($discordResult === 'sent')<x-alert class="mt-4" tone="ok">{{ __('Test Discord notification sent successfully.') }}</x-alert>@endif
            @if ($discordResult === 'failed')<x-alert class="mt-4" tone="error">{{ __('Test Discord notification could not be sent. Check the webhook configuration and application log.') }}</x-alert>@endif

            <form class="mt-4" method="POST" action="{{ route('admin.api-health.test-discord') }}">
                @csrf
                <x-button type="submit" :disabled="! $discordConfiguration['configured']">Send test notification</x-button>
            </form>
        </x-card>

        @if ($health)
            <p class="text-sm text-slate-500 dark:text-slate-400">Checked at {{ $health['checked_at'] }}</p>
            <div class="grid gap-5 lg:grid-cols-2">
                @foreach (['shopify' => 'Shopify', 'shipstation' => 'ShipStation'] as $key => $label)
                    @php($result = $health[$key])
                    <x-card>
                        <div class="flex items-center justify-between gap-4">
                            <h2 class="text-xl font-bold">{{ $label }}</h2>
                            <x-badge :tone="$result['ok'] ? 'ok' : 'danger'">{{ $result['ok'] ? 'Healthy' : 'Needs attention' }}</x-badge>
                        </div>
                        @if ($result['error'])<x-alert class="mt-4" tone="error">{{ $result['error'] }}</x-alert>@endif
                        <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-5 gap-y-2 text-sm">
                            <dt class="text-slate-500">{{ __('Configured') }}</dt><dd>{{ $result['configured'] ? 'Yes' : 'No' }}</dd>
                            <dt class="text-slate-500">{{ __('Latency') }}</dt><dd>{{ $result['latency_ms'] === null ? '—' : $result['latency_ms'].' ms' }}</dd>
                            @if ($key === 'shopify')
                                <dt class="text-slate-500">{{ __('Shop') }}</dt><dd>{{ $result['shop_name'] ?: '—' }}</dd>
                                <dt class="text-slate-500">{{ __('Requested API version') }}</dt><dd>{{ $result['requested_version'] ?: '—' }}</dd>
                                <dt class="text-slate-500">{{ __('Returned API version') }}</dt><dd class="{{ $result['version_matches'] ? '' : 'text-red-600 dark:text-red-400' }}">{{ $result['returned_version'] ?: 'Missing' }}</dd>
                                <dt class="text-slate-500">{{ __('Scopes') }}</dt><dd>{{ $result['scopes'] === [] ? '—' : implode(', ', $result['scopes']) }}</dd>
                                <dt class="text-slate-500">{{ __('Missing scopes') }}</dt><dd class="{{ $result['missing_scopes'] === [] ? '' : 'text-red-600 dark:text-red-400' }}">{{ $result['missing_scopes'] === [] ? 'None' : implode(', ', $result['missing_scopes']) }}</dd>
                            @endif
                        </dl>
                    </x-card>
                @endforeach
            </div>
        @endif

        <x-card>
            <div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-xl font-bold">{{ __('Report flow history') }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('Latest status from the active store\'s persisted run history.') }}</p></div><p class="text-sm"><span class="text-emerald-700">{{ $flowHealth['summary']['healthy'] }} healthy</span> · <span class="text-red-700">{{ $flowHealth['summary']['attention'] }} need attention</span></p></div>
            @if ($flowHealth['flows'] === [])
                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">{{ __('No report runs recorded yet.') }}</p>
            @else
                <x-data-table class="mt-4" :headers="['Tool', 'Status', 'Runs', 'Errors', 'Last run', 'Last error']">
                    @foreach ($flowHealth['flows'] as $flow)
                        <tr>
                            <td class="px-4 py-3">{{ $flow['tool'] }}</td>
                            <td class="px-4 py-3">{{ $flow['status'] }}</td>
                            <td class="px-4 py-3">{{ $flow['runs'] }}</td>
                            <td class="px-4 py-3">{{ $flow['errors'] }}</td>
                            <td class="px-4 py-3">{{ $flow['last_run_at'] }}</td>
                            <td class="px-4 py-3">{{ $flow['last_error'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif
        </x-card>
    </div>
@endsection
