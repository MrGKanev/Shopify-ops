<?php

namespace App\Http\Controllers;

use App\Application\Auth\LoginThrottle;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login', ['googleConfigured' => $this->googleConfigured(), 'googleLoginOnly' => (bool) config('services.google.login_only')]);
    }

    public function store(LoginRequest $request, LoginThrottle $throttle): RedirectResponse
    {
        if ((bool) config('services.google.login_only')) {
            return back()->withErrors(['email' => 'Password sign-in is disabled. Continue with Google.'])->onlyInput('email');
        }

        $ip = (string) $request->ip();

        if ($message = $throttle->bannedMessage($ip)) {
            return back()->withErrors(['email' => $message])->onlyInput('email');
        }

        if (! Auth::attempt($request->validated())) {
            return back()
                ->withErrors(['email' => $throttle->recordFailureMessage($ip)])
                ->onlyInput('email');
        }

        $throttle->recordSuccess($ip);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function googleConfigured(): bool
    {
        return trim((string) config('services.google.client_id')) !== ''
            && trim((string) config('services.google.client_secret')) !== ''
            && trim((string) config('services.google.redirect')) !== ''
            && trim((string) config('services.google.allowed_domains')) !== '';
    }
}
