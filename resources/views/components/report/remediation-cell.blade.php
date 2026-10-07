@props(['number', 'action', 'form' => 'order-remediation'])
<td class="px-4 py-3">
    <label class="inline-flex items-center gap-2">
        <input type="checkbox" name="order_numbers[]" value="{{ $number }}" form="{{ $form }}" aria-label="{{ __('Select order :order', ['order' => $number]) }}" class="rounded">
        <a class="text-indigo-600 dark:text-indigo-400" href="{{ route('orders.remediation.create', ['order_number' => $number, 'action' => $action]) }}">{{ __('Review') }}</a>
    </label>
</td>
