@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Settings" subtitle="Configuration overview for {{ $store->label }}." />

        <div class="grid gap-5 lg:grid-cols-2">
            <x-card>
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-xl font-bold">Connections</h2>
                    <a class="text-sm font-semibold text-indigo-600 dark:text-indigo-400" href="{{ route('admin.api-health') }}">Test connections</a>
                </div>
                @foreach ($connections as $name => $configured)
                    <div class="mt-4 flex items-center justify-between gap-4">
                        <span>{{ $name }}</span>
                        <x-badge :tone="$configured ? 'ok' : 'warn'">{{ $configured ? 'Configured' : 'Needs configuration' }}</x-badge>
                    </div>
                @endforeach
                <a class="mt-5 inline-block text-sm font-semibold text-indigo-600 dark:text-indigo-400" href="{{ route('admin.stores.edit', $store) }}">Edit store credentials</a>
            </x-card>

            <x-card>
                <h2 class="text-xl font-bold">Notifications</h2>
                @foreach ($notifications as $name => $channel)
                    <div class="mt-4 flex items-center justify-between gap-4">
                        <div>
                            <p>{{ $name }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $channel['rules'] }} active rules</p>
                        </div>
                        <x-badge :tone="$channel['configured'] ? 'ok' : 'warn'">{{ $channel['configured'] ? 'Configured' : 'Needs configuration' }}</x-badge>
                    </div>
                @endforeach
                <div class="mt-5 flex flex-wrap gap-4 text-sm font-semibold text-indigo-600 dark:text-indigo-400">
                    <a href="{{ route('admin.slack-rules.edit') }}">Manage notification rules</a>
                    <a href="{{ route('admin.webhook-health') }}">Webhook health</a>
                </div>
            </x-card>
        </div>

        <x-card>
            <div>
                <h2 class="text-xl font-bold">Branding &amp; custom links</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Customize the shared logo, login artwork, and utility links shown at the top right of every page.</p>
            </div>

            <form class="mt-5 flex flex-col gap-6" method="POST" action="{{ route('admin.appearance.update') }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div class="grid gap-5 lg:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium" for="site_name">Site name</label>
                        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="site_name" name="site_name" value="{{ old('site_name', $appSettings->displayName()) }}" maxlength="80" required>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Used as fallback text and the browser title.</p>
                        @error('site_name') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium" for="logo">Site logo</label>
                        @if ($appSettings->logoUrl())
                            <img class="h-12 max-w-56 object-contain object-left" src="{{ $appSettings->logoUrl() }}" alt="Current site logo">
                        @endif
                        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950" id="logo" name="logo" type="file" accept=".jpg,.jpeg,.png,.webp">
                        <p class="text-xs text-slate-500 dark:text-slate-400">PNG, JPG, or WebP · up to 2 MB · maximum 1600×800.</p>
                        @if ($appSettings->logo_path)
                            <label class="flex items-center gap-2 text-sm"><input class="size-4" name="remove_logo" type="checkbox" value="1"> Remove logo and show the site name</label>
                        @endif
                        @error('logo') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col gap-2 lg:col-span-2">
                        <label class="text-sm font-medium" for="login_image">Login image</label>
                        <img class="h-40 w-full rounded-lg object-cover" src="{{ $appSettings->loginImageUrl() }}" alt="Current login artwork">
                        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950" id="login_image" name="login_image" type="file" accept=".jpg,.jpeg,.png,.webp">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Landscape images work best · up to 5 MB · maximum 3840×3840.</p>
                        @if ($appSettings->login_image_path)
                            <label class="flex items-center gap-2 text-sm"><input class="size-4" name="remove_login_image" type="checkbox" value="1"> Restore the default login image</label>
                        @endif
                        @error('login_image') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                @php($links = old('custom_links', $appSettings->custom_links ?? []))
                <fieldset class="flex flex-col gap-3">
                    <legend class="text-sm font-semibold">Custom links</legend>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Add up to five HTTPS links. Empty rows are ignored.</p>
                    @for ($index = 0; $index < 5; $index++)
                        @php($link = $links[$index] ?? [])
                        <div class="grid gap-3 rounded-lg border border-slate-200 p-3 md:grid-cols-[1fr_2fr_1fr] dark:border-slate-800">
                            <div class="flex flex-col gap-1">
                                <label class="text-xs font-medium" for="custom_link_label_{{ $index }}">Label</label>
                                <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="custom_link_label_{{ $index }}" name="custom_links[{{ $index }}][label]" value="{{ $link['label'] ?? '' }}" maxlength="40">
                                @error("custom_links.{$index}.label") <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs font-medium" for="custom_link_url_{{ $index }}">URL</label>
                                <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="custom_link_url_{{ $index }}" name="custom_links[{{ $index }}][url]" type="url" value="{{ $link['url'] ?? '' }}" placeholder="https://example.com">
                                @error("custom_links.{$index}.url") <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs font-medium" for="custom_link_audience_{{ $index }}">Audience</label>
                                <select class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="custom_link_audience_{{ $index }}" name="custom_links[{{ $index }}][audience]">
                                    <option value="all" @selected(($link['audience'] ?? 'all') === 'all')>All users</option>
                                    <option value="operator" @selected(($link['audience'] ?? '') === 'operator')>Operators &amp; admins</option>
                                    <option value="admin" @selected(($link['audience'] ?? '') === 'admin')>Admins only</option>
                                </select>
                                @error("custom_links.{$index}.audience") <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endfor
                </fieldset>

                <div><x-button type="submit">Save appearance</x-button></div>
            </form>
        </x-card>

        <x-card>
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-xl font-bold">Security</h2>
                <x-badge :tone="$bannedIpCount > 0 ? 'warn' : 'ok'">{{ $bannedIpCount }} banned {{ $bannedIpCount === 1 ? 'IP' : 'IPs' }}</x-badge>
            </div>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Login abuse is limited automatically by Laravel's per-email and per-IP throttle; repeat offenders are banned for 1 week.</p>
            <div class="mt-3 flex flex-wrap gap-4 text-sm font-semibold text-indigo-600 dark:text-indigo-400">
                <a href="{{ route('admin.banned-ips.index') }}">Manage banned IPs</a>
                <a href="{{ route('admin.action-log') }}">View administration activity</a>
            </div>
        </x-card>

        <x-card>
            <h2 class="text-xl font-bold">Operations</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Health, backups, and diagnostics installed in this admin.</p>
            <div class="mt-3 flex flex-wrap gap-4 text-sm font-semibold text-indigo-600 dark:text-indigo-400">
                <a href="{{ route('admin.health') }}">Diagnostics</a>
                <a href="{{ route('admin.health-incidents') }}">Incidents</a>
                <a href="{{ route('admin.webhook-events') }}">Webhook events</a>
                <a href="{{ route('admin.backups.index') }}">Backups</a>
                <a href="{{ route('admin.stores.index') }}">Stores</a>
                <a href="{{ route('admin.users.index') }}">Users</a>
            </div>
        </x-card>
    </div>
@endsection
