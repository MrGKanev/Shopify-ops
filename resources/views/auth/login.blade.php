@extends('layouts.guest')
@section('content')
<div class="login-split"><div class="login-split-left"><div class="login-card"><div class="logo">Shopify Ops</div><div class="sub">{{ config('app.name') }}</div>
@error('google')<div class="error-msg">{{ $message }}</div>@enderror @error('email')<div class="error-msg">{{ $message }}</div>@enderror
@if($googleConfigured)<a class="btn btn-full google-login-btn" href="{{ route('auth.google.redirect') }}"><span class="google-mark" aria-hidden="true">G</span>Continue with Google</a>@elseif($googleLoginOnly)<div class="error-msg">Google sign-in is enabled but its configuration is incomplete.</div>@endif
@if($googleConfigured && !$googleLoginOnly)<div class="login-dev-sep">or</div>@endif
@unless($googleLoginOnly)<form method="POST" action="{{ route('login.store') }}">@csrf<div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></div><div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><button class="btn btn-full" type="submit">Sign in</button></form>@endunless
</div></div><div class="login-split-right"></div></div>
@endsection
