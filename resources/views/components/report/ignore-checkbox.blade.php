{{-- Row checkbox for the bulk-ignore form (`@include('partials.bulk-ignore-form')`) as a table cell. --}}
@props(['number'])
<td {{ $attributes->merge(['class' => 'px-4 py-3']) }}><input type="checkbox" name="order_numbers[]" value="{{ $number }}" form="bulk-ignore" aria-label="{{ __('Select order :number', ['number' => $number]) }}"></td>
