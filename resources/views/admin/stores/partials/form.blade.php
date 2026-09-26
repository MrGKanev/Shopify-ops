<div class="grid gap-5 sm:grid-cols-2">
    <div class="flex flex-col gap-2 sm:col-span-2">
        <label class="text-sm font-medium" for="label">{{ __('Store name') }}</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="label" name="label" value="{{ old('label', $store?->label) }}" required>
        @error('label') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium" for="slug">{{ __('Internal slug') }}</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="slug" name="slug" value="{{ old('slug', $store?->slug) }}" required>
        @error('slug') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium" for="shopify_store">{{ __('Shopify store') }}</label>
        <div class="flex rounded-lg border border-slate-300 bg-white dark:border-slate-700 dark:bg-slate-950">
            <input class="min-w-0 grow rounded-l-lg bg-transparent px-3 py-2" id="shopify_store" name="shopify_store" value="{{ old('shopify_store', $store?->shopify_store) }}" required>
            <span class="border-l border-slate-300 px-3 py-2 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">.myshopify.com</span>
        </div>
        @error('shopify_store') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2 sm:col-span-2">
        <label class="text-sm font-medium" for="shopify_access_token">{{ __('Shopify access token') }}</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="shopify_access_token" name="shopify_access_token" type="password" autocomplete="new-password" {{ $store === null ? 'required' : '' }}>
        @if ($store !== null) <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Leave blank to keep the current token.') }}</p> @endif
        @error('shopify_access_token') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2 sm:col-span-2">
        <label class="text-sm font-medium" for="shopify_webhook_secret">{{ __('Shopify webhook signing secret') }}</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="shopify_webhook_secret" name="shopify_webhook_secret" type="password" autocomplete="new-password">
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Used only to verify incoming webhook signatures. Leave blank to keep the current secret.') }}</p>
        @error('shopify_webhook_secret') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium" for="shipstation_api_key">{{ __('ShipStation API key') }}</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="shipstation_api_key" name="shipstation_api_key" type="password" autocomplete="new-password">
        @if ($store !== null) <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Leave blank to keep the current key.') }}</p> @endif
        @error('shipstation_api_key') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium" for="shipstation_api_secret">{{ __('ShipStation API secret') }}</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="shipstation_api_secret" name="shipstation_api_secret" type="password" autocomplete="new-password">
        @if ($store !== null) <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Leave blank to keep the current secret.') }}</p> @endif
        @error('shipstation_api_secret') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2 sm:col-span-2">
        <label class="text-sm font-medium" for="store_number">{{ __('ShipStation store number') }}</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="store_number" name="store_number" value="{{ old('store_number', $store?->store_number) }}">
        @error('store_number') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>

    <div class="flex flex-col gap-3 rounded-lg border border-slate-200 p-4 sm:col-span-2 dark:border-slate-800">
        <div class="flex items-center gap-2"><input id="scheduled_audit_enabled" name="scheduled_audit_enabled" type="checkbox" value="1" @checked(old('scheduled_audit_enabled', $store?->scheduled_audit_enabled))><label class="text-sm font-medium" for="scheduled_audit_enabled">{{ __('Run the core audit automatically every day') }}</label></div>
        <div class="flex max-w-xs flex-col gap-2"><label class="text-sm font-medium" for="scheduled_audit_time">{{ __('Local run time') }}</label><input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="scheduled_audit_time" name="scheduled_audit_time" type="time" value="{{ old('scheduled_audit_time', $store?->scheduled_audit_time?->format('H:i')) }}"><p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Uses the application timezone and scans the previous 30 days.') }}</p></div>
    </div>

    <div class="flex flex-col gap-2 sm:col-span-2">
        <label class="text-sm font-medium" for="delivery_watch_days">Delivery watch threshold (days)</label>
        <input class="max-w-xs rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="delivery_watch_days" name="delivery_watch_days" type="number" min="1" max="90" value="{{ old('delivery_watch_days', $store?->delivery_watch_days ?? 5) }}" required>
        <p class="text-xs text-slate-500 dark:text-slate-400">Create an issue if no delivery confirmation appears after this many days.</p>
        @error('delivery_watch_days') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>
</div>

<div class="mt-6 flex items-center gap-3">
    <x-button type="submit">Save store</x-button>
    <a class="text-sm font-semibold text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white" href="{{ route('admin.stores.index') }}">Cancel</a>
</div>
