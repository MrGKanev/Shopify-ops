{{--
    Result section of a report: heading (`heading` slot, optional `summary` line
    under it), optional CSV export form,
    truncated-results warning, then the default slot (tables, cards).
    `:export-params` become hidden inputs; `truncated-message` is translated with :pages.
--}}
@props([
    'exportRoute' => null,
    'exportParams' => [],
    'truncated' => false,
    'truncatedMessage' => 'Results are incomplete: orders truncated after :pages pages.',
    'pages' => null,
])
<section {{ $attributes->merge(['class' => 'flex flex-col gap-4']) }}>
    @if (isset($heading) || $exportRoute)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold">{{ $heading ?? '' }}</h2>
                @isset($summary)
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $summary }}</p>
                @endisset
            </div>
            @if ($exportRoute)
                <form method="POST" action="{{ route($exportRoute) }}">
                    @csrf
                    @foreach ($exportParams as $name => $value)
                        @continue($value === null)
                        <input type="hidden" name="{{ $name }}" value="{{ is_bool($value) ? (int) $value : $value }}">
                    @endforeach
                    <x-button type="submit" variant="ghost">{{ __('Download CSV') }}</x-button>
                </form>
            @endif
        </div>
    @endif
    @if ($truncated)
        <x-alert tone="warn">{{ __($truncatedMessage, ['pages' => $pages]) }}</x-alert>
    @endif

    {{ $slot }}
</section>
