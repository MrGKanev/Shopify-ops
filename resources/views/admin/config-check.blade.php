@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Config Check" subtitle="Runtime validation of Laravel configuration and the active store." />

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
@endsection
