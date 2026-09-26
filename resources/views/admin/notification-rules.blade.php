@extends('layouts.app')

@section('content')
    <div class="flex max-w-4xl flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Notifications" subtitle="Slack, Discord, and email rules for the active store, in one place." />

        <div class="flex flex-wrap gap-2 border-b border-slate-200 dark:border-slate-800" role="tablist" data-tabs>
            <button class="rounded-t-lg border border-b-0 border-transparent px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900 aria-selected:border-slate-200 aria-selected:bg-white aria-selected:text-indigo-600 dark:text-slate-400 dark:hover:text-slate-100 dark:aria-selected:border-slate-800 dark:aria-selected:bg-slate-900 dark:aria-selected:text-indigo-400" type="button" role="tab" data-tab-target="slack" aria-selected="{{ $activeTab === 'slack' ? 'true' : 'false' }}">{{ __('Slack') }}</button>
            <button class="rounded-t-lg border border-b-0 border-transparent px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900 aria-selected:border-slate-200 aria-selected:bg-white aria-selected:text-indigo-600 dark:text-slate-400 dark:hover:text-slate-100 dark:aria-selected:border-slate-800 dark:aria-selected:bg-slate-900 dark:aria-selected:text-indigo-400" type="button" role="tab" data-tab-target="discord" aria-selected="{{ $activeTab === 'discord' ? 'true' : 'false' }}">{{ __('Discord') }}</button>
            <button class="rounded-t-lg border border-b-0 border-transparent px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900 aria-selected:border-slate-200 aria-selected:bg-white aria-selected:text-indigo-600 dark:text-slate-400 dark:hover:text-slate-100 dark:aria-selected:border-slate-800 dark:aria-selected:bg-slate-900 dark:aria-selected:text-indigo-400" type="button" role="tab" data-tab-target="email" aria-selected="{{ $activeTab === 'email' ? 'true' : 'false' }}">{{ __('Email') }}</button>
        </div>

        <div data-tab-panel="slack" @if ($activeTab !== 'slack') hidden @endif>
            <div class="flex flex-col gap-5 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                @unless ($slackConfigured)
                    <x-alert tone="warn">{{ __('Slack webhook is not configured.') }}</x-alert>
                @endunless

                <form class="flex flex-col gap-5" method="POST" action="{{ route('admin.slack-rules.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                            <input class="size-4 rounded border-slate-300 text-indigo-600" name="audit_enabled" type="checkbox" value="1" @checked(old('audit_enabled', $slackRules['audit_enabled']))>
                            <span class="text-sm font-medium">{{ __('Audit notifications enabled') }}</span>
                        </label>
                        <div>
                            <label class="text-sm font-medium" for="slack_audit_min_missing">{{ __('Minimum missing orders') }}</label>
                            <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="slack_audit_min_missing" min="0" name="audit_min_missing" type="number" value="{{ old('audit_min_missing', $slackRules['audit_min_missing']) }}">
                            @error('audit_min_missing')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>@enderror
                        </div>
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                            <input class="size-4 rounded border-slate-300 text-indigo-600" name="include_zero_audit" type="checkbox" value="1" @checked(old('include_zero_audit', $slackRules['include_zero_audit']))>
                            <span class="text-sm font-medium">{{ __('Send all-clear notifications') }}</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                            <input class="size-4 rounded border-slate-300 text-indigo-600" name="scan_enabled" type="checkbox" value="1" @checked(old('scan_enabled', $slackRules['scan_enabled']))>
                            <span class="text-sm font-medium">{{ __('Scan notifications enabled') }}</span>
                        </label>
                        <div>
                            <label class="text-sm font-medium" for="slack_scan_min_rows">{{ __('Minimum scan rows') }}</label>
                            <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="slack_scan_min_rows" min="1" name="scan_min_rows" type="number" value="{{ old('scan_min_rows', $slackRules['scan_min_rows']) }}">
                            @error('scan_min_rows')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>@enderror
                        </div>
                        <div>
                            <label class="text-sm font-medium" for="mentions">{{ __('Slack member/group IDs') }}</label>
                            <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="mentions" maxlength="500" name="mentions" value="{{ old('mentions', $slackRules['mentions']) }}" placeholder="U012ABC3DE S024XYZ9FG">
                        </div>
                    </div>

                    <x-button class="self-start" type="submit">Save Slack rules</x-button>
                </form>
            </div>
        </div>

        <div data-tab-panel="discord" @if ($activeTab !== 'discord') hidden @endif>
            <div class="flex flex-col gap-5 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                @unless ($discordConfigured)
                    <x-alert tone="warn">{{ __('Discord webhook is not configured.') }}</x-alert>
                @endunless

                <form class="flex flex-col gap-5" method="POST" action="{{ route('admin.discord-rules.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                            <input class="size-4 rounded border-slate-300 text-indigo-600" name="audit_enabled" type="checkbox" value="1" @checked(old('audit_enabled', $discordRules['audit_enabled']))>
                            <span class="text-sm font-medium">{{ __('Audit notifications enabled') }}</span>
                        </label>
                        <div>
                            <label class="text-sm font-medium" for="discord_audit_min_missing">{{ __('Minimum missing orders') }}</label>
                            <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="discord_audit_min_missing" min="0" name="audit_min_missing" type="number" value="{{ old('audit_min_missing', $discordRules['audit_min_missing']) }}">
                            @error('audit_min_missing')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>@enderror
                        </div>
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                            <input class="size-4 rounded border-slate-300 text-indigo-600" name="include_zero_audit" type="checkbox" value="1" @checked(old('include_zero_audit', $discordRules['include_zero_audit']))>
                            <span class="text-sm font-medium">{{ __('Send all-clear notifications') }}</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                            <input class="size-4 rounded border-slate-300 text-indigo-600" name="scan_enabled" type="checkbox" value="1" @checked(old('scan_enabled', $discordRules['scan_enabled']))>
                            <span class="text-sm font-medium">{{ __('Scan notifications enabled') }}</span>
                        </label>
                        <div>
                            <label class="text-sm font-medium" for="discord_scan_min_rows">{{ __('Minimum scan rows') }}</label>
                            <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="discord_scan_min_rows" min="1" name="scan_min_rows" type="number" value="{{ old('scan_min_rows', $discordRules['scan_min_rows']) }}">
                            @error('scan_min_rows')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>@enderror
                        </div>
                    </div>

                    <x-button class="self-start" type="submit">Save Discord rules</x-button>
                </form>
            </div>
        </div>

        <div data-tab-panel="email" @if ($activeTab !== 'email') hidden @endif>
            <div class="flex flex-col gap-5 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <form class="flex flex-col gap-5" method="POST" action="{{ route('admin.email-rules.update') }}">
                    @csrf
                    @method('PUT')

                    <div>
                        <label class="text-sm font-medium" for="default_alert_email">{{ __('Default alert email') }}</label>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Used when a tool\'s own recipient is blank.') }}</p>
                        <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="default_alert_email" name="default_alert_email" type="email" value="{{ old('default_alert_email', $defaultAlertEmail) }}">
                        @error('default_alert_email')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>@enderror
                    </div>

                    @foreach ($rules as $tool => $rule)
                        <fieldset class="grid gap-4 rounded-xl border border-slate-200 p-4 sm:grid-cols-4 dark:border-slate-800">
                            <legend class="px-2 font-semibold">{{ $catalog[$tool]['label'] ?? $tool }}</legend>
                            <div>
                                <label class="text-sm font-medium" for="mode-{{ $loop->index }}">{{ __('Delivery') }}</label>
                                <select class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="mode-{{ $loop->index }}" name="rules[{{ $tool }}][mode]">
                                    @foreach (['off' => 'Off', 'immediate' => 'Immediate', 'digest' => 'Daily digest'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old("rules.$tool.mode", $rule['mode']) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-medium" for="threshold-{{ $loop->index }}">{{ __('Minimum rows') }}</label>
                                <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="threshold-{{ $loop->index }}" min="0" name="rules[{{ $tool }}][threshold]" type="number" value="{{ old("rules.$tool.threshold", $rule['threshold']) }}">
                            </div>
                            <div>
                                <label class="text-sm font-medium" for="email-{{ $loop->index }}">{{ __('Recipient') }}</label>
                                <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="email-{{ $loop->index }}" name="rules[{{ $tool }}][email]" type="email" value="{{ old("rules.$tool.email", $rule['email']) }}">
                            </div>
                            <label class="flex items-end gap-2 pb-2 text-sm font-medium">
                                <input name="rules[{{ $tool }}][include_zero]" type="hidden" value="0">
                                <input class="mb-0.5 size-4 rounded border-slate-300 text-indigo-600" name="rules[{{ $tool }}][include_zero]" type="checkbox" value="1" @checked(old("rules.$tool.include_zero", $rule['include_zero']))>
                                {{ __('Include zero rows') }}
                            </label>
                        </fieldset>
                    @endforeach

                    @error('rules.*')<p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>@enderror
                    <x-button class="self-start" type="submit">Save email rules</x-button>
                </form>
            </div>
        </div>
    </div>
@endsection
