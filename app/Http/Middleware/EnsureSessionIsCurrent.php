<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionIsCurrent
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard();
        if ($request->user() !== null
            && $request->session()->has($guard->getName())
            && ! $request->session()->has('password_hash_'.Auth::getDefaultDriver())) {
            $guard->logoutCurrentDevice();
            $request->session()->invalidate();
            throw new AuthenticationException;
        }

        return app(AuthenticateSession::class)->handle($request, $next);
    }
}
