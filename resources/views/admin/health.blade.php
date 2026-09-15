@extends('layouts.app')

@section('content')
<div class="flex flex-col gap-6"><x-page-header eyebrow="Administration" title="Operational Health" subtitle="Live checks for Laravel, infrastructure, storage, backups, queue, and scheduler." /><x-card><h2 class="font-semibold">Operations shortcuts</h2><div class="mt-3 flex flex-wrap gap-2">@foreach ($quickLinks as $link)<x-button size="sm" variant="ghost" :href="route($link['route'])">{{ $link['label'] }}</x-button>@endforeach</div></x-card><div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">@foreach ($checks as $check)<x-card><div class="flex items-start justify-between gap-3"><div><h2 class="font-semibold">{{ $check['label'] }}</h2><p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $check['detail'] }}</p></div><x-badge :tone="$check['ok'] ? 'ok' : 'danger'">{{ $check['ok'] ? 'OK' : 'Check' }}</x-badge></div></x-card>@endforeach</div></div>
@endsection
