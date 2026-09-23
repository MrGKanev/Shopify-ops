@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Diagnostics" subtitle="Operational health and configuration validation for the active store." />

        <x-card>
            <h2 class="font-semibold">Operations shortcuts</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($quickLinks as $link)
                    <x-button size="sm" variant="ghost" :href="route($link['route'])">{{ $link['label'] }}</x-button>
                @endforeach
            </div>
        </x-card>

        <div class="flex flex-wrap gap-2 border-b border-slate-200 dark:border-slate-800" role="tablist" data-tabs>
            <button class="rounded-t-lg border border-b-0 border-transparent px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900 aria-selected:border-slate-200 aria-selected:bg-white aria-selected:text-indigo-600 dark:text-slate-400 dark:hover:text-slate-100 dark:aria-selected:border-slate-800 dark:aria-selected:bg-slate-900 dark:aria-selected:text-indigo-400" type="button" role="tab" data-tab-target="health" aria-selected="{{ $activeTab === 'health' ? 'true' : 'false' }}">Operational Health</button>
            <button class="rounded-t-lg border border-b-0 border-transparent px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900 aria-selected:border-slate-200 aria-selected:bg-white aria-selected:text-indigo-600 dark:text-slate-400 dark:hover:text-slate-100 dark:aria-selected:border-slate-800 dark:aria-selected:bg-slate-900 dark:aria-selected:text-indigo-400" type="button" role="tab" data-tab-target="config" aria-selected="{{ $activeTab === 'config' ? 'true' : 'false' }}">Config Check</button>
        </div>

        <div data-tab-panel="health" @if ($activeTab !== 'health') hidden @endif>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($checks as $check)
                    <x-card>
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="font-semibold">{{ $check['label'] }}</h2>
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $check['detail'] }}</p>
                            </div>
                            <x-badge :tone="$check['ok'] ? 'ok' : 'danger'">{{ $check['ok'] ? 'OK' : 'Check' }}</x-badge>
                        </div>
                    </x-card>
                @endforeach
            </div>
        </div>

        <div data-tab-panel="config" @if ($activeTab !== 'config') hidden @endif>
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($results as $result)
                    <x-card>
                        <div class="flex items-center justify-between gap-4">
                            <h2 class="text-xl font-bold">{{ $result['name'] }}</h2>
                            <x-badge :tone="$result['ok'] ? 'ok' : 'danger'">{{ $result['ok'] ? 'Valid' : 'Needs attention' }}</x-badge>
                        </div>

                        @foreach ($result['issues'] as $issue)
                            <x-alert class="mt-3" tone="error">{{ $issue }}</x-alert>
                        @endforeach

                        <ul class="mt-3 list-disc pl-5 text-sm text-slate-500 dark:text-slate-400">
                            @foreach ($result['notes'] as $note)
                                <li>{{ $note }}</li>
                            @endforeach
                        </ul>
                    </x-card>
                @endforeach
            </div>
        </div>
    </div>
@endsection
