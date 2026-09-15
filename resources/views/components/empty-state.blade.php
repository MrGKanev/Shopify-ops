@props(['icon' => '—', 'title' => null])
<div {{ $attributes->merge(['class' => 'rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500 dark:border-slate-700 dark:text-slate-400']) }}>
    <div class="text-3xl">{{ $icon }}</div>
    @if ($title)
        <h3 class="mt-3 text-base font-semibold text-slate-700 dark:text-slate-300">{{ $title }}</h3>
    @endif
    <p class="mt-1 text-sm">{{ $slot }}</p>
</div>
