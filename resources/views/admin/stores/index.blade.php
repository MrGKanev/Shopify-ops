@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Stores">
            <x-button :href="route('admin.stores.create')">Add store</x-button>
        </x-page-header>

        <x-data-table :headers="['Store', 'Shopify', 'Users', 'Action']">
            @forelse ($stores as $store)
                <tr>
                    <td class="px-4 py-3">
                        <p class="font-semibold">{{ $store->label }}</p>
                        <p class="text-slate-500 dark:text-slate-400">{{ $store->slug }}</p>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $store->shopify_store }}.myshopify.com</td>
                    <td class="px-4 py-3">{{ $store->users_count }}</td>
                    <td class="px-4 py-3 text-right"><x-button :href="route('admin.stores.edit', $store)" size="sm" variant="ghost">Edit</x-button></td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="4">{{ __('No stores configured.') }}</td></tr>
            @endforelse
        </x-data-table>

        @if ($stores->hasPages())
            {{ $stores->links() }}
        @endif
    </div>
@endsection
