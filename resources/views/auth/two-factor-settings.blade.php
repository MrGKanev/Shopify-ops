@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-xl space-y-6">
        <x-page-header title="Two-factor authentication" subtitle="Protect your account with an authenticator app." />
        @if (auth()->user()->hasEnabledTwoFactorAuthentication())
            <x-alert tone="success">{{ __('Two-factor authentication is enabled. Store these recovery codes somewhere safe.') }}</x-alert>
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><ul class="grid grid-cols-2 gap-2 font-mono text-sm">@foreach(auth()->user()->recoveryCodes() as $code)<li>{{ $code }}</li>@endforeach</ul></div>
            <form method="POST" action="{{ route('two-factor.disable') }}">@csrf @method('DELETE')<button class="rounded-lg border border-red-300 px-4 py-2 text-red-700" type="submit">{{ __('Disable two-factor authentication') }}</button></form>
        @elseif (auth()->user()->two_factor_secret)
            <p>{{ __('Scan this code with Google Authenticator, 1Password, or another authenticator app, then enter its six-digit code.') }}</p>
            <div class="w-fit rounded-xl bg-white p-4">{!! auth()->user()->twoFactorQrCodeSvg() !!}</div>
            <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex gap-3">@csrf <input class="rounded-lg border px-3 py-2" name="code" inputmode="numeric" autocomplete="one-time-code" required><button class="rounded-lg bg-indigo-600 px-4 py-2 text-white" type="submit">{{ __('Confirm') }}</button></form>
        @else
            <form method="POST" action="{{ route('two-factor.enable') }}">@csrf <button class="rounded-lg bg-indigo-600 px-4 py-2 text-white" type="submit">{{ __('Enable two-factor authentication') }}</button></form>
        @endif
    </div>
@endsection
