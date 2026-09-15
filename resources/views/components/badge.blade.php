@props(['tone' => 'default'])
@php
    $tones = [
        'default' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
        'ok' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
        'warn' => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
        'danger' => 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
        'info' => 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    ];
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center rounded px-2 py-1 text-xs font-semibold {$tones[$tone]}"]) }}>{{ $slot }}</span>
