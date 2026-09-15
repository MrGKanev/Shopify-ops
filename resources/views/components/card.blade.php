@props(['padding' => 'p-5'])
<div {{ $attributes->merge(['class' => "rounded-xl border border-slate-200 bg-white {$padding} dark:border-slate-800 dark:bg-slate-900"]) }}>
    {{ $slot }}
</div>
