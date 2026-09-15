<?php

namespace App\Http\Controllers;

use App\Application\Auth\LoginThrottle;
use App\Http\Requests\LoginRequest;
use App\Models\Store;
use App\Models\User;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login', [
            'googleConfigured' => $this->googleConfigured(),
            'googleLoginOnly' => (bool) config('services.google.login_only'),
            'isLocal' => app()->environment('local'),
        ]);
    }

    public function devLogin(Request $request, string $role): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $store = Store::firstOrCreate(
            ['slug' => 'dev-store'],
            ['label' => 'Dev Store', 'shopify_store' => 'dev-store', 'shopify_access_token' => 'dev-token'],
        );

        $user = User::firstOrCreate(
            ['email' => "dev-{$role}@local.test"],
            ['name' => ucfirst($role).' (Dev)', 'password' => Hash::make('password'), 'role' => UserRole::from($role)],
        );

        if (! $user->stores()->where('stores.id', $store->id)->exists()) {
            $user->stores()->attach($store);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
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
