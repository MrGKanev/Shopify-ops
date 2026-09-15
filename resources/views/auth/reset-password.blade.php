@extends('layouts.guest')
@section('content')
<div class="login-split"><div class="login-split-left"><div class="login-card">@if($appSettings->logoUrl())<img class="login-logo" src="{{ $appSettings->logoUrl() }}" alt="{{ $appSettings->displayName() }}">@else<div class="logo">{{ $appSettings->displayName() }}</div>@endif<div class="sub">Choose a new password</div>
@error('email')<div class="error-msg">{{ $message }}</div>@enderror @error('password')<div class="error-msg">{{ $message }}</div>@enderror
<form method="POST" action="{{ route('password.update') }}">@csrf<input name="token" type="hidden" value="{{ $token }}"><div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required autofocus></div><div class="field"><label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" required></div><div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div><button class="btn btn-full" type="submit">Reset password</button></form>
</div></div><div class="login-split-right" aria-hidden="true"><img src="{{ $appSettings->loginImageUrl() }}" alt=""></div></div>
@endsection
