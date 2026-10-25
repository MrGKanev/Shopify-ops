@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" :title="__('Report schedule')" :subtitle="__($registry->find($schedule->tool)?->label ?? $schedule->tool)" />
        @if ($errors->any())<x-alert tone="error"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-alert>@endif
        @if ($schedule->tool === 'run_audit')<x-alert tone="warn">{{ __('A Run Audit schedule replaces this store’s legacy daily audit automation, including when the new schedule is paused.') }}</x-alert>@endif
        @if ($schedule->tool === 'operational_digest')<x-alert tone="warn">{{ __('An Operational Digest schedule replaces automatic digest generation from Email Rules, including while paused.') }}</x-alert>@endif
        @if (in_array($schedule->tool, ['shipment_backlog', 'shopify_backlog'], true))<x-alert tone="warn">{{ __('Backlog history preserves the first successful observation of each store-local day. Running it again on that day reuses the observation.') }}</x-alert>@endif
        <x-card>
            <form class="flex flex-col gap-5" method="POST" action="{{ $schedule->exists ? route('admin.report-schedules.update', $schedule) : route('admin.report-schedules.store') }}">
                @csrf
                @if ($schedule->exists)@method('PUT')@endif
                @if ($source)<input type="hidden" name="source_run_id" value="{{ $source->id }}">@else<input type="hidden" name="tool" value="{{ $schedule->tool }}">@endif
                <label class="flex flex-col gap-2">{{ __('Name') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" name="name" maxlength="100" required value="{{ old('name', $schedule->name) }}"></label>
                <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $schedule->enabled))>{{ __('Enable automatic execution') }}</label>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="flex flex-col gap-2">{{ __('Frequency') }}<select class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" name="frequency">@foreach (['daily', 'weekly', 'monthly'] as $frequency)<option value="{{ $frequency }}" @selected(old('frequency', $schedule->frequency) === $frequency)>{{ __(ucfirst($frequency)) }}</option>@endforeach</select></label>
                    <label class="flex flex-col gap-2">{{ __('Time') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" type="time" name="time" required value="{{ old('time', $schedule->time) }}"></label>
                    <label class="flex flex-col gap-2">{{ __('Timezone') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" name="timezone" required maxlength="64" value="{{ old('timezone', $schedule->timezone) }}"></label>
                    <label class="flex flex-col gap-2">{{ __('Day for weekly schedules') }}<select class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" name="weekday">@foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $value => $day)<option value="{{ $value }}" @selected((int) old('weekday', $schedule->weekday) === $value)>{{ __($day) }}</option>@endforeach</select></label>
                    <label class="flex flex-col gap-2">{{ __('Day for monthly schedules') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" type="number" name="month_day" min="1" max="28" required value="{{ old('month_day', $schedule->month_day) }}"></label>
                </div>
                @if ($schedule->uses_date_range)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex flex-col gap-2">{{ __('Days to include') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" type="number" name="lookback_days" min="1" max="365" required value="{{ old('lookback_days', $schedule->lookback_days) }}"></label>
                        <label class="flex flex-col gap-2">{{ __('End period this many days ago') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" type="number" name="end_offset_days" min="0" max="30" required value="{{ old('end_offset_days', $schedule->end_offset_days) }}"></label>
                    </div>
                    <p>{{ __('The date range moves at each execution. An end offset of 1 ends yesterday; 0 includes today.') }}</p>
                @else
                    <input type="hidden" name="lookback_days" value="30"><input type="hidden" name="end_offset_days" value="1">
                @endif
                @if ($schedule->parameters !== [])
                    <div><h2 class="font-semibold">{{ __('Saved filters') }}</h2><dl>@foreach ($schedule->parameters as $key => $value)@continue(in_array($key, ['start_date', 'end_date', 'after'], true))<div><dt>{{ __(ucfirst(str_replace('_', ' ', $key))) }}</dt><dd>{{ is_bool($value) ? __($value ? 'Yes' : 'No') : (is_scalar($value) ? $value : implode(', ', (array) $value)) }}</dd></div>@endforeach</dl></div>
                @endif
                <label class="flex items-center gap-2"><input type="checkbox" name="notification_enabled" value="1" @checked(old('notification_enabled', $schedule->notification_enabled))>{{ __('Email only when findings meet the threshold') }}</label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="flex flex-col gap-2">{{ __('Recipient email') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" type="email" name="notification_email" maxlength="254" value="{{ old('notification_email', $schedule->notification_email) }}"></label>
                    <label class="flex flex-col gap-2">{{ __('Minimum findings') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" type="number" name="notification_threshold" min="1" max="100000" required value="{{ old('notification_threshold', $schedule->notification_threshold) }}"></label>
                </div>
                <div class="flex flex-wrap gap-3"><x-button type="submit">{{ __('Save schedule') }}</x-button><x-button variant="ghost" :href="route('admin.report-schedules.index')">{{ __('Back') }}</x-button></div>
            </form>
        </x-card>
    </div>
@endsection
