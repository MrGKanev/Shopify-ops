@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Webhook Health" subtitle="Live Shopify webhook registrations for the active store.">
            <x-button size="sm" variant="ghost" :href="route('admin.webhook-events')">Event history</x-button>
        </x-page-header>

        <x-card>
            <p class="text-sm font-semibold">{{ __('Callback URL') }}</p>
            <p class="mt-2 break-all font-mono text-sm text-slate-600 dark:text-slate-300">{{ route('webhooks.shopify', $activeStore->slug) }}</p>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Configure this HTTPS address in Shopify and save the signing secret in the store settings.') }}</p>
        </x-card>

        @if ($error)
            <x-alert tone="error">{{ $error }}</x-alert>
        @elseif ($webhooks === [])
            <x-empty-state title="No Shopify webhooks are registered." />
        @else
            <x-data-table :headers="['Status', 'Topic', 'Endpoint', 'Format', 'Registered', 'API version']">
                @foreach ($webhooks as $webhook)
                    <tr>
                        <td class="px-4 py-3"><x-badge :tone="$webhook['healthy'] ? 'ok' : 'warn'">{{ $webhook['healthy'] ? 'Healthy' : 'Review' }}</x-badge></td>
                        <td class="px-4 py-3">{{ $webhook['topic'] ?: '—' }}</td>
                        <td class="px-4 py-3 break-all">{{ $webhook['address'] ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $webhook['format'] }}</td>
                        <td class="px-4 py-3">{{ $webhook['created_at'] ? substr($webhook['created_at'], 0, 10) : '—' }}</td>
                        <td class="px-4 py-3">{{ $webhook['api_version'] ?: '—' }}</td>
                    </tr>
                @endforeach
            </x-data-table>
        @endif
    </div>
@endsection
