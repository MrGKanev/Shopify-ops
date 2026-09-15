@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Settings" subtitle="Configuration overview for {{ $store->label }}." />

        <div class="grid gap-5 lg:grid-cols-2">
            <x-card>
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-xl font-bold">Connections</h2>
                    <a class="text-sm font-semibold text-indigo-600 dark:text-indigo-400" href="{{ route('admin.api-health') }}">Test connections</a>
                </div>
                @foreach ($connections as $name => $configured)
                    <div class="mt-4 flex items-center justify-between gap-4">
                        <span>{{ $name }}</span>
                        <x-badge :tone="$configured ? 'ok' : 'warn'">{{ $configured ? 'Configured' : 'Needs configuration' }}</x-badge>
                    </div>
                @endforeach
                <a class="mt-5 inline-block text-sm font-semibold text-indigo-600 dark:text-indigo-400" href="{{ route('admin.stores.edit', $store) }}">Edit store credentials</a>
            </x-card>

            <x-card>
                <h2 class="text-xl font-bold">Notifications</h2>
                @foreach ($notifications as $name => $channel)
                    <div class="mt-4 flex items-center justify-between gap-4">
                        <div>
                            <p>{{ $name }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $channel['rules'] }} active rules</p>
                        </div>
                        <x-badge :tone="$channel['configured'] ? 'ok' : 'warn'">{{ $channel['configured'] ? 'Configured' : 'Needs configuration' }}</x-badge>
                    </div>
                @endforeach
                <div class="mt-5 flex flex-wrap gap-4 text-sm font-semibold text-indigo-600 dark:text-indigo-400">
                    <a href="{{ route('admin.slack-rules.edit') }}">Slack rules</a>
                    <a href="{{ route('admin.discord-rules.edit') }}">Discord rules</a>
                    <a href="{{ route('admin.email-rules.edit') }}">Email rules</a>
                </div>
            </x-card>
        </div>

        <x-card>
            <h2 class="text-xl font-bold">Security</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Login abuse is limited automatically by Laravel's per-email and per-IP throttle; there is no persistent IP ban list to maintain.</p>
            <a class="mt-3 inline-block text-sm font-semibold text-indigo-600 dark:text-indigo-400" href="{{ route('admin.action-log') }}">View administration activity</a>
        </x-card>
    </div>
@endsection
