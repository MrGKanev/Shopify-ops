@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section class="flex flex-col gap-2">
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">{{ __('Read-only workflow') }}</p>
            <h1 class="text-3xl font-bold">{{ __('Tracking feed') }}</h1>
            <p class="text-slate-500 dark:text-slate-400">{{ __('Look up 1–30 orders and their actual ShipStation shipments.') }}</p>
        </section>

        <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('orders.tracking.store') }}">
            @csrf

            <label class="text-sm font-medium" for="orders">{{ __('Order numbers') }}</label>
            <textarea class="mt-2 min-h-32 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950" id="orders" name="orders" maxlength="4096" aria-invalid="{{ $errors->has('orders') ? 'true' : 'false' }}" @if ($errors->has('orders')) aria-describedby="orders-error" @endif>{{ old('orders', $ordersInput) }}</textarea>
            @error('orders')
                <p class="mt-2 text-sm text-red-600 dark:text-red-400" id="orders-error" role="alert">{{ __($message) }}</p>
            @enderror

            <div class="mt-4">
                <x-button type="submit">Load tracking</x-button>
            </div>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">{{ __('ShipStation is not configured completely for this store.') }}</x-alert>
        @endif
        @if ($lookupFailed)
            <x-alert tone="error">{{ __('Tracking could not be loaded. Try again.') }}</x-alert>
        @endif

        @if ($results !== null)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ __('Results') }}</h2>
                @foreach ($results as $result)
                    <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex justify-between gap-3">
                            <h3 class="font-semibold">#{{ $result['number'] }}</h3>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs dark:bg-slate-800">{{ ! $result['found'] ? 'Not found' : (count($result['shipments']) > 1 ? count($result['shipments']).' shipments' : 'Found') }}</span>
                        </div>
                        @if (! $result['found'])
                            <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ __('Not found in ShipStation.') }}</p>
                        @else
                            <div class="mt-4 grid gap-3">
                                @foreach ($result['shipments'] as $shipment)
                                    <div class="rounded-lg bg-slate-50 p-4 text-sm dark:bg-slate-950">
                                        <div class="flex flex-wrap gap-3">
                                            <strong>{{ str_replace('_', ' ', $shipment['orderStatus'] ?: 'unknown') }}</strong>
                                            @if ($shipment['carrierCode'])
                                                <span>{{ strtoupper($shipment['carrierCode']) }}</span>
                                            @endif
                                            @if ($shipment['shipDate'])
                                                <span>{{ $shipment['shipDate'] }}</span>
                                            @endif
                                        </div>
                                        <div class="mt-2">
                                            @if ($shipment['trackingNumber'])
                                                @if ($shipment['trackingUrl'])
                                                    <a class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400" href="{{ $shipment['trackingUrl'] }}" target="_blank" rel="noopener noreferrer">{{ $shipment['trackingNumber'] }}</a>
                                                @else
                                                    <span>{{ $shipment['trackingNumber'] }}</span>
                                                @endif
                                            @else
                                                <span class="text-slate-500 dark:text-slate-400">{{ __('No tracking number yet') }}</span>
                                            @endif
                                        </div>
                                        @if ($shipment['ssUrl'])
                                            <a class="mt-2 inline-flex text-indigo-600 hover:text-indigo-500 dark:text-indigo-400" href="{{ $shipment['ssUrl'] }}" target="_blank" rel="noopener noreferrer">{{ __('Open in ShipStation') }}</a>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endforeach
            </section>
        @endif
    </div>
@endsection
