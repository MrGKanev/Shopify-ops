<div class="grid gap-5 sm:grid-cols-2">
    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium" for="name">Name</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="name" name="name" value="{{ old('name', $user?->name) }}" required>
        @error('name') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium" for="email">{{ __('Email') }}</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="email" name="email" type="email" value="{{ old('email', $user?->email) }}" autocomplete="username" required>
        @error('email') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2 sm:col-span-2">
        <label class="text-sm font-medium" for="role">Role</label>
        <select class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="role" name="role" required>
            @foreach (\App\UserRole::cases() as $role)
                <option value="{{ $role->value }}" @selected(old('role', $user?->role?->value) === $role->value)>{{ ucfirst($role->value) }}</option>
            @endforeach
        </select>
        @error('role') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium" for="password">{{ __('Password') }}</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="password" name="password" type="password" minlength="12" autocomplete="new-password" {{ $user === null ? 'required' : '' }}>
        @if ($user !== null) <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Leave blank to keep the current password.') }}</p> @endif
        @error('password') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium" for="password_confirmation">{{ __('Confirm password') }}</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="password_confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" {{ $user === null ? 'required' : '' }}>
    </div>

    <fieldset class="flex flex-col gap-3 sm:col-span-2">
        <legend class="text-sm font-medium">{{ __('Store access') }}</legend>
        @php($selectedStores = old('store_ids', $user?->stores->modelKeys() ?? []))
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($stores as $store)
                <label class="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-3 dark:border-slate-800">
                    <input class="size-4 rounded border-slate-300 text-indigo-600" name="store_ids[]" type="checkbox" value="{{ $store->getKey() }}" @checked(in_array($store->getKey(), $selectedStores))>
                    <span class="text-sm font-medium">{{ $store->label }}</span>
                </label>
            @endforeach
        </div>
        @error('store_ids') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
        @error('store_ids.*') <p class="text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p> @enderror
    </fieldset>
</div>

<div class="mt-6 flex items-center gap-3">
    <x-button type="submit">Save user</x-button>
    <a class="text-sm font-semibold text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white" href="{{ route('admin.users.index') }}">Cancel</a>
</div>
