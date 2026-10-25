<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class SessionRevocationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_password_recovery_rejects_the_previous_session_and_allows_a_new_login(): void
    {
        config()->set('services.google.login_only', false);
        [$user] = $this->userWithStore();
        $user->update(['password' => 'old-password']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'old-password'])->assertRedirect();
        $this->get(route('dashboard'))->assertOk();
        $oldSession = session()->all();
        $oldRememberToken = $user->fresh()->remember_token;
        $token = Password::createToken($user);

        $this->flushSession();
        Auth::forgetGuards();
        $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertRedirect(route('login'));
        $this->assertNotSame($oldRememberToken, $user->fresh()->remember_token);

        $this->withSession($oldSession);
        Auth::forgetGuards();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'new-secure-password'])->assertRedirect();
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_admin_password_change_revokes_the_victims_session_and_remember_token(): void
    {
        config()->set('services.google.login_only', false);
        [$user, $store] = $this->userWithStore();
        $user->update(['password' => 'victim-password']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'victim-password'])->assertRedirect();
        $oldSession = session()->all();
        $oldToken = $user->fresh()->remember_token;
        $admin = User::factory()->admin()->create(['password' => 'admin-password']);
        $admin->stores()->attach($store);
        $this->flushSession();
        Auth::forgetGuards();
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'admin-password'])->assertRedirect();
        $this->put(route('admin.users.update', $user), ['name' => $user->name, 'email' => $user->email, 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password', 'role' => 'viewer', 'store_ids' => [$store->id]])->assertRedirect();
        $this->assertNotSame($oldToken, $user->fresh()->remember_token);

        $this->flushSession();
        $this->withSession($oldSession);
        Auth::forgetGuards();
        $this->getJson('/user/two-factor-secret-key')->assertUnauthorized();
    }

    public function test_legacy_authenticated_session_without_a_credential_stamp_requires_login(): void
    {
        [$user] = $this->userWithStore();
        $guard = Auth::guard();
        $this->withSession([$guard->getName() => $user->id]);
        Auth::forgetGuards();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
