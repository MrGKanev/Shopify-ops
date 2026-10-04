{{--
    Link to a Shopify admin resource of the active store (opens in a new tab).
    Falls back to the plain label when the id is missing. Extra classes apply to both.
--}}
@props(['id' => null, 'resource' => 'orders'])
@if ($id)
    <a {{ $attributes->class('text-indigo-600 dark:text-indigo-400') }} href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/{{ $resource }}/{{ $id }}" target="_blank" rel="noopener noreferrer">{{ $slot }}</a>
@else
    <span {{ $attributes }}>{{ $slot }}</span>
@endif
