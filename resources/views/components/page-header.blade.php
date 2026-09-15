@props(['eyebrow' => null, 'title', 'subtitle' => null])
<section class="flex flex-wrap items-start justify-between gap-4">
    <div>
        @if ($eyebrow)
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">{{ $eyebrow }}</p>
        @endif
        <h1 class="mt-1 text-3xl font-bold">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-2 text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex gap-2">{{ $slot }}</div>
    @endif
</section>
