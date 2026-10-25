@extends('layouts.guest')

@section('content')
    <main class="mx-auto max-w-md space-y-6 p-8">
        <x-page-header title="{{ __('Confirm your identity') }}" />
        <x-card>
            @if (config('services.google.login_only') && ! auth()->user()->hasEnabledTwoFactorAuthentication())
                <p>{{ __('Sign out and sign in with Google again to manage two-factor authentication.') }}</p>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-button type="submit">{{ __('Sign out') }}</x-button>
                </form>
            @else
                <form class="space-y-4" method="POST" action="{{ route('password.confirm.store') }}">
                    @csrf
                    <label class="block" for="password">{{ config('services.google.login_only') ? __('Authenticator code') : __('Current password') }}</label>
                    <input class="w-full rounded border p-2 dark:border-slate-700 dark:bg-slate-950" id="password" name="password" type="password" autocomplete="{{ config('services.google.login_only') ? 'one-time-code' : 'current-password' }}" required autofocus>
                    @error('password')<x-alert tone="error">{{ $message }}</x-alert>@enderror
                    <x-button type="submit">{{ __('Continue') }}</x-button>
                </form>
            @endif
        </x-card>
    </main>
@endsection
