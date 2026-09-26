@props(['label', 'value', 'tone' => 'default'])
@php
    $tones = [
        'default' => 'border-slate-200 dark:border-slate-800',
        'warn' => 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950',
        'ok' => 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950',
    ];
@endphp
<article {{ $attributes->merge(['class' => "rounded-xl border bg-white p-5 dark:bg-slate-900 {$tones[$tone]}"]) }}>
    <div class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ __($label) }}</div>
    <div class="mt-1 text-2xl font-bold">{{ $value }}</div>
    @isset($sub)
        <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">{!! $sub !!}</div>
    @endisset
</article>
