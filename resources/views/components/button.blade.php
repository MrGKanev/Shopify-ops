@props(['variant' => 'primary', 'size' => 'md', 'href' => null])
@php
    $base = 'inline-flex items-center justify-center gap-1.5 rounded-lg font-semibold transition disabled:cursor-not-allowed disabled:opacity-50';
    $sizes = ['md' => 'px-5 py-2.5 text-sm', 'sm' => 'px-3 py-1.5 text-xs'];
    $variants = [
        'primary' => 'bg-indigo-600 text-white hover:bg-indigo-500',
        'ghost' => 'border border-slate-300 text-slate-700 hover:border-slate-400 dark:border-slate-700 dark:text-slate-300 dark:hover:border-slate-600',
        'danger' => 'bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-950 dark:text-red-300 dark:hover:bg-red-900',
    ];
    $classes = trim("{$base} {$sizes[$size]} {$variants[$variant]}");
@endphp
@if ($href)
    <a {{ $attributes->merge(['class' => $classes, 'href' => $href]) }}>
        @php($buttonText = trim((string) $slot))
        @if (str_contains($buttonText, '<')) {!! $slot !!} @else {{ __($buttonText) }} @endif
    </a>
@else
    <button {{ $attributes->merge(['class' => $classes, 'type' => 'button']) }}>
        @php($buttonText = trim((string) $slot))
        @if (str_contains($buttonText, '<')) {!! $slot !!} @else {{ __($buttonText) }} @endif
    </button>
@endif
