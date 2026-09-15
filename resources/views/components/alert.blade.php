@props(['tone' => 'ok'])
@php
    $tones = [
        'ok' => 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-200 dark:border-emerald-800',
        'warn' => 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:border-amber-800',
        'error' => 'bg-red-50 text-red-800 border-red-200 dark:bg-red-950 dark:text-red-200 dark:border-red-800',
    ];
@endphp
<div {{ $attributes->merge(['class' => "rounded-xl border p-4 {$tones[$tone]}"]) }} role="alert">{{ $slot }}</div>
