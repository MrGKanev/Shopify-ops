<form id="bulk-ignore" method="POST" action="{{ route('ignored-orders.bulk-store') }}" class="flex flex-wrap items-center gap-2">
    @csrf
    <label class="sr-only" for="bulk-ignore-reason">{{ __('Reason for ignoring selected orders') }}</label>
    <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="bulk-ignore-reason" name="reason" placeholder="Reason (optional)">
    <x-button type="submit">{{ __('Ignore selected') }}</x-button>
    <span class="text-sm text-slate-500 dark:text-slate-400">{{ __('Excludes selected orders from Run Audit.') }}</span>
</form>
