@extends('layouts.app')

@section('content')
    <div class="flex max-w-3xl flex-col gap-6">
        <x-page-header eyebrow="Administration · Stores" :title="__('Edit :name', ['name' => $store->label])" />

        <form class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('admin.stores.update', $store) }}">
            @csrf
            @method('PUT')
            @include('admin.stores.partials.form', ['store' => $store])
        </form>
        <x-card>
            <h2 class="text-lg font-semibold">{{ __('ShipStation synchronization monitoring') }}</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Optional and off by default. After you enable it, shipments are checked against Shopify after a 15-minute sync grace period. Missing fulfillment or tracking creates a review issue; orders are never changed automatically.') }}</p>
            <p class="mt-2 text-sm">{{ __('Status') }}: {{ __(match ($store->shipstation_monitoring_status) { 'active' => 'Monitoring active', 'pending' => 'Setup queued', 'stopping' => 'Subscription removal queued', 'failed' => 'Monitoring needs attention', default => 'Monitoring disabled' }) }}</p>
            @if ($store->shipstation_monitoring_checked_at)
                <p class="mt-2 text-sm">{{ __('Last catch-up') }}: {{ $store->shipstation_monitoring_checked_at->format('Y-m-d H:i') }}</p>
            @endif
            <p class="mt-2 text-sm">{{ __('Failed events') }}: {{ $store->shipstation_failed_events_count }}</p>
            @error('shipstation_monitoring') <x-alert tone="error" class="mt-3">{{ $message }}</x-alert> @enderror
            <form method="POST" action="{{ route('admin.stores.shipstation-monitoring', $store) }}" class="mt-4">
                @csrf
                <input type="hidden" name="enabled" value="{{ $store->shipstation_monitoring_enabled ? '0' : '1' }}">
                <x-button type="submit">{{ $store->shipstation_monitoring_enabled ? __('Disable monitoring') : __('Enable monitoring') }}</x-button>
            </form>
            @if (! $store->shipstation_monitoring_enabled && $store->shipstation_monitoring_token !== null)
                <form method="POST" action="{{ route('admin.stores.shipstation-monitoring', $store) }}" class="mt-3">
                    @csrf
                    <input type="hidden" name="enabled" value="0">
                    <x-button type="submit" variant="ghost">{{ __('Retry subscription removal') }}</x-button>
                </form>
            @endif
        </x-card>
    </div>
@endsection
