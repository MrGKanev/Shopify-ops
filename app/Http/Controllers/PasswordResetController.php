<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function create(): View
    {
        $this->ensurePasswordLoginEnabled();

        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensurePasswordLoginEnabled();
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', 'If an account exists for that email, a password reset link has been sent.');
    }

    public function edit(Request $request, string $token): View
    {
        $this->ensurePasswordLoginEnabled();

        return view('auth.reset-password', ['email' => (string) $request->string('email'), 'token' => $token]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->ensurePasswordLoginEnabled();
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])
                    ->setRememberToken(Str::random(60));
                $user->save();

                event(new PasswordReset($user));
            },
        );

        return $status === Password::PasswordReset
            ? redirect()->route('login')->with('status', __($status))
            : back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
    }

    private function ensurePasswordLoginEnabled(): void
    {
        abort_if((bool) config('services.google.login_only'), 404);
    }
}
