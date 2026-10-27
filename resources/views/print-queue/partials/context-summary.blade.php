@php
    $summary = match ($row['status']) {
        'first' => __('First order'),
        'repeat' => __('Repeat customer').' · '.($row['atLeast'] ? '≥ ' : '').$row['priorOrders'].' '.__('earlier orders'),
        default => __('Customer history unknown'),
    };
@endphp
{{ $summary }}@if ($row['tier']) · {{ $row['tier'] === 'vip' ? __('VIP') : __('Milestone') }}@endif @if ($row['repeatedSkus'] !== []) · {{ __('Bought before') }}: {{ implode(', ', $row['repeatedSkus']) }}@endif
