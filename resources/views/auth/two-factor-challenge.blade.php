@extends('layouts.guest')

@section('content')
    <main class="mx-auto max-w-md p-8"><h1 class="text-2xl font-bold">Two-factor authentication</h1><form class="mt-6 space-y-4" method="POST" action="{{ route('two-factor.login.store') }}">@csrf <label class="block">{{ __('Authenticator code') }}<input class="mt-1 w-full rounded border p-2" name="code" inputmode="numeric" autocomplete="one-time-code"></label><label class="block">{{ __('Or recovery code') }}<input class="mt-1 w-full rounded border p-2" name="recovery_code"></label><button class="rounded bg-indigo-600 px-4 py-2 text-white" type="submit">{{ __('Continue') }}</button></form></main>
@endsection
