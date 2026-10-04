{{--
    Run form for a report over a date range. Extra inputs go in `:fields`
    (same spec as x-report.params-form) and are placed after the dates.
--}}
@props(['action', 'startDate', 'endDate', 'fields' => [], 'submitLabel' => 'Run report', 'submitDisabled' => false, 'columns' => null])
<x-report.params-form
    :action="$action"
    :fields="['start_date' => ['label' => 'From', 'type' => 'date', 'value' => $startDate], 'end_date' => ['label' => 'To', 'type' => 'date', 'value' => $endDate]] + $fields"
    :submit-label="$submitLabel"
    :submit-disabled="$submitDisabled"
    :columns="$columns"
    {{ $attributes }}
>
    {{ $slot }}
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset
</x-report.params-form>
