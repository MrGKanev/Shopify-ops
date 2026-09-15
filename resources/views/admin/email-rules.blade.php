@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration · Notifications" title="Email Rules" subtitle="Send immediate alerts or include completed reports in the daily digest." />

        <form class="flex flex-col gap-5 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900" method="POST">
            @csrf
            @method('PUT')

            <div>
                <label class="text-sm font-medium" for="default_alert_email">Default alert email</label>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Used when a tool's own recipient is blank.</p>
                <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="default_alert_email" name="default_alert_email" type="email" value="{{ old('default_alert_email', $defaultAlertEmail) }}">
                @error('default_alert_email')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
            </div>

            @foreach ($rules as $tool => $rule)
                <fieldset class="grid gap-4 rounded-xl border border-slate-200 p-4 sm:grid-cols-4 dark:border-slate-800">
                    <legend class="px-2 font-semibold">{{ $catalog[$tool]['label'] ?? $tool }}</legend>
                    <div>
                        <label class="text-sm font-medium" for="mode-{{ $loop->index }}">Delivery</label>
                        <select class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="mode-{{ $loop->index }}" name="rules[{{ $tool }}][mode]">
                            @foreach (['off' => 'Off', 'immediate' => 'Immediate', 'digest' => 'Daily digest'] as $value => $label)
                                <option value="{{ $value }}" @selected(old("rules.$tool.mode", $rule['mode']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium" for="threshold-{{ $loop->index }}">Minimum rows</label>
                        <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="threshold-{{ $loop->index }}" min="0" name="rules[{{ $tool }}][threshold]" type="number" value="{{ old("rules.$tool.threshold", $rule['threshold']) }}">
                    </div>
                    <div>
                        <label class="text-sm font-medium" for="email-{{ $loop->index }}">Recipient</label>
                        <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="email-{{ $loop->index }}" name="rules[{{ $tool }}][email]" type="email" value="{{ old("rules.$tool.email", $rule['email']) }}">
                    </div>
                    <label class="flex items-end gap-2 pb-2 text-sm font-medium">
                        <input name="rules[{{ $tool }}][include_zero]" type="hidden" value="0">
                        <input class="mb-0.5 size-4 rounded border-slate-300 text-indigo-600" name="rules[{{ $tool }}][include_zero]" type="checkbox" value="1" @checked(old("rules.$tool.include_zero", $rule['include_zero']))>
                        Include zero rows
                    </label>
                </fieldset>
            @endforeach

            @error('rules.*')<p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
            <x-button class="self-start" type="submit">Save rules</x-button>
        </form>
    </div>
@endsection
