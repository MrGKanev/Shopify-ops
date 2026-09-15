@extends('layouts.guest')
@section('content')
<div class="login-split"><div class="login-split-left"><div class="login-card">@if($appSettings->logoUrl())<img class="login-logo" src="{{ $appSettings->logoUrl() }}" alt="{{ $appSettings->displayName() }}">@else<div class="logo">{{ $appSettings->displayName() }}</div>@endif<div class="sub">Reset your password</div>
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif @error('email')<div class="error-msg">{{ $message }}</div>@enderror
<form method="POST" action="{{ route('password.email') }}">@csrf<div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus></div><button class="btn btn-full" type="submit">Send reset link</button></form>
<a class="mt-4 block text-center text-sm text-indigo-600 hover:underline" href="{{ route('login') }}">Back to sign in</a>
</div></div><div class="login-split-right" aria-hidden="true"><img src="{{ $appSettings->loginImageUrl() }}" alt=""></div></div>
@endsection
