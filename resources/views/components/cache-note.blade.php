@props(['minutes'])
@php
    $duration = $minutes >= 60 && $minutes % 60 === 0
        ? trans_choice('{1} :count hour|[2,*] :count hours', intdiv($minutes, 60))
        : trans_choice('{1} :count minute|[2,*] :count minutes', $minutes);
    $message = __('This data is cached for up to :duration, so recent changes in Shopify or ShipStation may not show up right away.', ['duration' => $duration]);
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400']) }} title="{{ $message }}">
    <svg aria-hidden="true" class="shrink-0" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4M12 8h.01"></path></svg>
    <span>{{ $message }}</span>
    {{ $slot }}
</div>
