@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="{{ __('Webhook Health') }}" subtitle="Live Shopify webhook registrations for the active store.">
            <x-button size="sm" variant="ghost" :href="route('admin.webhook-events')">{{ __('Event history') }}</x-button>
        </x-page-header>

        @error('webhooks')<x-alert tone="error">{{ __($message) }}</x-alert>@enderror
        @error('subscription_id')<x-alert tone="error">{{ __($message) }}</x-alert>@enderror

        <x-card>
            <p class="text-sm font-semibold">{{ __('Callback URL') }}</p>
            <p class="mt-2 break-all font-mono text-sm text-slate-600 dark:text-slate-300">{{ $callback }}</p>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Use a public HTTPS APP_URL and save the Shopify app client secret as the signing secret in the store settings. API-created subscriptions use the app secret; manually created admin webhooks can use a different secret.') }}</p>
        </x-card>

        @if ($ok)
            <x-card>
                <p class="text-sm">{{ __('Missing topics: :topics', ['topics' => $missing === [] ? __('None') : implode(', ', $missing)]) }}</p>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Only subscriptions visible to the current Shopify app are listed. Event API versions come from app settings and cannot be changed per subscription.') }}</p>
                <form method="POST" action="{{ route('admin.webhook-health.register') }}" class="mt-3">@csrf
                    <x-button size="sm" type="submit">{{ __('Register missing topics') }}</x-button>
                </form>
            </x-card>
        @endif

        @if ($error)
            <x-alert tone="error">{{ $error }}</x-alert>
        @elseif ($webhooks === [])
            <x-empty-state title="{{ __('No Shopify webhooks are registered.') }}" />
        @else
            <x-data-table :headers="['Status', 'Topic', 'Endpoint', 'Format', 'Registered', 'API version', 'Action']">
                @foreach ($webhooks as $webhook)
                    <tr>
                        <td class="px-4 py-3"><x-badge :tone="$webhook['healthy'] ? 'ok' : 'warn'">{{ $webhook['healthy'] ? 'Healthy' : 'Review' }}</x-badge></td>
                        <td class="px-4 py-3">{{ $webhook['topic'] ?: '—' }}</td>
                        <td class="px-4 py-3 break-all">{{ $webhook['address'] ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $webhook['format'] }}</td>
                        <td class="px-4 py-3">{{ $webhook['created_at'] ? substr($webhook['created_at'], 0, 10) : '—' }}</td>
                        <td class="px-4 py-3">{{ $webhook['api_version'] ?: '—' }}</td>
                        <td class="px-4 py-3">@if ($webhook['removable'])
                            <form method="POST" action="{{ route('admin.webhook-health.destroy') }}">@csrf @method('DELETE')
                                <input type="hidden" name="subscription_id" value="{{ $webhook['id'] }}">
                                <x-button size="sm" type="submit" variant="ghost">{{ __('Remove outdated') }}</x-button>
                            </form>
                        @endif</td>
                    </tr>
                @endforeach
            </x-data-table>
        @endif
    </div>
@endsection
