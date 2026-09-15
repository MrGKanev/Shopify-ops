@extends('layouts.app')

@section('content')
    <div class="flex max-w-3xl flex-col gap-6">
        <x-page-header eyebrow="Administration · Notifications" title="Discord Rules" subtitle="Control audit and scan notifications for the active store." />

        @unless ($configured)
            <x-alert tone="warn">Discord webhook is not configured.</x-alert>
        @endunless

        <form class="flex flex-col gap-5 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900" method="POST">
            @csrf
            @method('PUT')

            <div class="grid gap-5 sm:grid-cols-2">
                <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                    <input class="size-4 rounded border-slate-300 text-indigo-600" name="audit_enabled" type="checkbox" value="1" @checked(old('audit_enabled', $rules['audit_enabled']))>
                    <span class="text-sm font-medium">Audit notifications enabled</span>
                </label>
                <div>
                    <label class="text-sm font-medium" for="audit_min_missing">Minimum missing orders</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="audit_min_missing" min="0" name="audit_min_missing" type="number" value="{{ old('audit_min_missing', $rules['audit_min_missing']) }}">
                    @error('audit_min_missing')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                    <input class="size-4 rounded border-slate-300 text-indigo-600" name="include_zero_audit" type="checkbox" value="1" @checked(old('include_zero_audit', $rules['include_zero_audit']))>
                    <span class="text-sm font-medium">Send all-clear notifications</span>
                </label>
                <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                    <input class="size-4 rounded border-slate-300 text-indigo-600" name="scan_enabled" type="checkbox" value="1" @checked(old('scan_enabled', $rules['scan_enabled']))>
                    <span class="text-sm font-medium">Scan notifications enabled</span>
                </label>
                <div>
                    <label class="text-sm font-medium" for="scan_min_rows">Minimum scan rows</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="scan_min_rows" min="1" name="scan_min_rows" type="number" value="{{ old('scan_min_rows', $rules['scan_min_rows']) }}">
                    @error('scan_min_rows')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
            </div>

            <x-button class="self-start" type="submit">Save rules</x-button>
        </form>
    </div>
@endsection
