{{--
    Run form for a report. `:fields` maps input name => spec:
    ['label' => 'Older than days', 'type' => 'number', 'value' => $threshold, 'min' => 1, 'max' => 365].
    Keys other than label/type/value/class become input attributes, so a report
    definition can build the specs from its rules() and defaults(). The default
    slot is placed above the submit button (checkboxes, datalists); the `actions`
    slot follows it (extra submit buttons with a formaction).
--}}
@props(['action', 'fields' => [], 'submitLabel' => 'Run report', 'submitDisabled' => false, 'columns' => null])
@php
    $columnClasses = [2 => 'sm:grid-cols-2', 3 => 'sm:grid-cols-3', 4 => 'sm:grid-cols-4', 5 => 'sm:grid-cols-5'];
    $columns ??= count($fields) + 1;
    $inputClass = 'mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950';
@endphp
<form {{ $attributes->class(['grid gap-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900', $columnClasses[$columns] ?? '']) }} method="POST" action="{{ $action }}">
    @csrf
    @foreach ($fields as $name => $field)
        <div>
            <label class="text-sm font-medium" for="{{ $name }}">{{ __($field['label']) }}</label>
            <input
                {{ (new \Illuminate\View\ComponentAttributeBag(\Illuminate\Support\Arr::except($field, ['label', 'type', 'value', 'class'])))->class([$inputClass, $field['class'] ?? '']) }}
                id="{{ $name }}"
                name="{{ $name }}"
                type="{{ $field['type'] ?? 'text' }}"
                value="{{ old($name, $field['value'] ?? null) }}"
                @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
            >
            @error($name)
                <p class="mt-1 text-sm text-red-600 dark:text-red-400" id="{{ $name }}-error">{{ __($message) }}</p>
            @enderror
        </div>
    @endforeach
    <div @class(['flex gap-2', 'flex-col justify-end' => $slot->hasActualContent(), 'items-end' => ! $slot->hasActualContent()])>
        {{ $slot }}
        <x-button type="submit" :disabled="$submitDisabled">{{ $submitLabel }}</x-button>
        {{ $actions ?? '' }}
    </div>
</form>
