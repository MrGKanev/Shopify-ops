@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="{{ __('Fix orders') }}" subtitle="Review current Shopify and ShipStation data before applying an operation.">
            <x-button size="sm" variant="ghost" :href="route('jobs.index')">{{ __('Job Queue') }}</x-button>
        </x-page-header>
        @if ($errors->any())
            <x-alert tone="error"><ul class="list-disc pl-5">@foreach ($errors->all() as $message)<li>{{ __($message) }}</li>@endforeach</ul></x-alert>
        @endif
        <x-card>
            <form class="flex flex-col gap-4" method="POST" action="{{ route('orders.remediation.preview') }}">
                @csrf
                <label class="flex flex-col gap-1 text-sm font-medium">{{ __('Action') }}
                    <select name="action" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950">
                        @foreach ($actions as $value => $label)<option value="{{ $value }}" @selected(old('action', $selectedAction) === $value)>{{ __($label) }}</option>@endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-sm font-medium">{{ __('Order numbers (up to 50)') }}
                    @php($oldNumbers = old('order_numbers', $numbers))
                    <textarea name="order_numbers" rows="5" required class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950">{{ is_array($oldNumbers) ? implode("\n", array_filter($oldNumbers, is_scalar(...))) : $oldNumbers }}</textarea>
                </label>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Separate order numbers with new lines or commas. Every order is checked independently.') }}</p>
                <div class="grid gap-4 md:grid-cols-3">
                    <label class="flex flex-col gap-1 text-sm font-medium">{{ __('Shopify tag (tag actions only)') }}<input name="tag" maxlength="100" value="{{ old('tag') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950"></label>
                    <label class="flex flex-col gap-1 text-sm font-medium">{{ __('ShipStation tag ID (SS tag action only)') }}<input name="tag_id" type="number" min="1" value="{{ old('tag_id') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950"></label>
                    <label class="flex flex-col gap-1 text-sm font-medium">{{ __('Hold until (hold action only)') }}<input name="hold_until" type="date" value="{{ old('hold_until', now()->addDays(7)->toDateString()) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950"></label>
                </div>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('ShipStation releases its hold on the selected date. Shopify review holds remain until released. Tracking corrections do not send customer notifications. Split shipments and existing partial fulfillments require manual review.') }}</p>
                <div><x-button type="submit">{{ __('Preview changes') }}</x-button></div>
            </form>
        </x-card>
    </div>
@endsection
