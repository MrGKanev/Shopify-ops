{{--
    Wraps a <table>. Header: `:headers="['Order', 'Date']"` for plain labels,
    `:columns` (label => accessor) when the cells can be generated, or a `head`
    slot for anything richer (sort links, icon-only columns, a leading checkbox column).

    Body: the default slot holds caller-rendered rows (`@foreach ... <tr>`). When
    the slot is empty and `:columns` is given, rows are rendered from the accessors:
    a string reads `$row[key]`, a Closure receives the row, and an array
    `['value' => string|Closure, 'class' => '...']` adds cell classes. Closures may
    return an Htmlable for markup. Pass `:rows` with `empty="..."` to render the
    empty-state row instead of an `@forelse/@empty` block.
--}}
@props(['headers' => null, 'columns' => null, 'rows' => null, 'empty' => null, 'remediationActions' => [], 'remediationForm' => 'order-remediation'])
@php
    $labels = $columns !== null ? array_keys($columns) : $headers;
    $hasRows = $rows === null || count($rows) > 0;
    $cellValue = static function (mixed $accessor, mixed $row): mixed {
        $accessor = is_array($accessor) ? $accessor['value'] : $accessor;

        $value = $accessor instanceof Closure ? $accessor($row) : data_get($row, $accessor);

        return is_string($accessor) && in_array($accessor, ['status', 'financial', 'fulfillment', 'sh_fulfillment', 'order_status', 'ss_status'], true) && is_string($value)
            ? __($value)
            : $value;
    };
@endphp
@if ($remediationActions !== [] && $hasRows)
    <form id="{{ $remediationForm }}" method="GET" action="{{ route('orders.remediation.create') }}" class="mb-3 flex flex-wrap items-center gap-3">
        <label class="text-sm font-medium" for="{{ $remediationForm }}-action">{{ __('Fix selected orders') }}</label>
        <select id="{{ $remediationForm }}-action" name="action" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
            @foreach ($remediationActions as $action => $label)<option value="{{ $action }}">{{ __($label) }}</option>@endforeach
        </select>
        <x-button size="sm" type="submit">{{ __('Review selected') }}</x-button>
    </form>
@endif
<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900']) }}>
    <table class="min-w-full text-left text-sm">
        <thead>
            <tr>
                @if ($remediationActions !== [])<th class="px-4 py-3">{{ __('Fix') }}</th>@endif
                @if ($labels)
                    @foreach ($labels as $header)
                        <th class="px-4 py-3">{{ __($header) }}</th>
                    @endforeach
                @else
                    {{ $head }}
                @endif
            </tr>
        </thead>
        <tbody>
            @if (! $hasRows)
                <tr>
                    <td class="px-4 py-8 text-center text-slate-500" colspan="{{ ($labels ? count($labels) : 1) + (int) ($remediationActions !== []) }}">{{ __($empty) }}</td>
                </tr>
            @elseif ($slot->hasActualContent() || $columns === null)
                {{ $slot }}
            @else
                @foreach ($rows as $row)
                    <tr>
                        @if ($remediationActions !== [])
                            <x-report.remediation-cell :number="data_get($row, 'order_number')" :action="array_key_first($remediationActions)" :form="$remediationForm" />
                        @endif
                        @foreach ($columns as $accessor)
                            <td @class(['px-4 py-3', is_array($accessor) ? ($accessor['class'] ?? null) : null])>{{ $cellValue($accessor, $row) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            @endif
        </tbody>
    </table>
</div>
