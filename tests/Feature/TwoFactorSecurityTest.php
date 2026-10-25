<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Tests\TestCase;

class TwoFactorSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_mfa_secrets_and_changes_require_recent_confirmation(): void
    {
        [$user] = $this->userWithStore();
        $this->enableMfa($user);
        $this->getJson('/user/two-factor-secret-key')->assertUnauthorized();
        $this->actingAs($user)->get(route('two-factor.settings'))->assertRedirect(route('password.confirm'));
        foreach (['/user/two-factor-secret-key', '/user/two-factor-qr-code', '/user/two-factor-recovery-codes'] as $url) {
            $this->getJson($url)->assertStatus(423);
        }
        foreach (['/user/two-factor-authentication', '/user/confirmed-two-factor-authentication', '/user/two-factor-recovery-codes'] as $url) {
            $this->postJson($url)->assertStatus(423);
        }
        $this->deleteJson('/user/two-factor-authentication')->assertStatus(423);
        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    public function test_correct_password_allows_mfa_changes_only_for_five_minutes(): void
    {
        $this->freezeTime();
        config()->set('services.google.login_only', false);
        [$user] = $this->userWithStore();
        $user->update(['password' => 'current-secure-password']);
        $this->enableMfa($user);

        $this->actingAs($user)->get(route('password.confirm'))->assertOk()->assertSeeText('Current password');
        $this->postJson(route('password.confirm.store'), ['password' => 'wrong'])->assertUnprocessable();
        $this->getJson('/user/two-factor-secret-key')->assertStatus(423);
        $this->postJson(route('password.confirm.store'), ['password' => 'current-secure-password'])->assertCreated();
        $this->getJson('/user/two-factor-secret-key')->assertOk()->assertJsonPath('secretKey', 'JBSWY3DPEHPK3PXP');
        $this->get(route('two-factor.settings'))->assertOk()->assertSeeText('audit-recovery-code');

        $this->travel(301)->seconds();
        $this->getJson('/user/two-factor-secret-key')->assertStatus(423);
        $this->deleteJson('/user/two-factor-authentication')->assertStatus(423);
        $this->postJson(route('password.confirm.store'), ['password' => 'current-secure-password'])->assertCreated();
        $this->deleteJson('/user/two-factor-authentication')->assertSuccessful();
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_google_only_users_confirm_with_their_existing_authenticator(): void
    {
        config()->set('services.google.login_only', true);
        [$user] = $this->userWithStore();
        $user->update(['password' => 'disabled-local-password']);
        $this->enableMfa($user);
        $provider = $this->mock(TwoFactorAuthenticationProvider::class);
        $provider->shouldReceive('verify')->once()->with('JBSWY3DPEHPK3PXP', '654321')->andReturnFalse();
        $provider->shouldReceive('verify')->once()->with('JBSWY3DPEHPK3PXP', '123456')->andReturnTrue();

        $this->actingAs($user)->get(route('password.confirm'))->assertOk()->assertSeeText('Authenticator code');
        $this->postJson(route('password.confirm.store'), ['password' => 'disabled-local-password'])->assertUnprocessable();
        $this->getJson('/user/two-factor-secret-key')->assertStatus(423);
        $this->postJson(route('password.confirm.store'), ['password' => '654321'])->assertUnprocessable();
        $this->postJson(route('password.confirm.store'), ['password' => '123456'])->assertCreated();
        $this->getJson('/user/two-factor-recovery-codes')->assertOk()->assertJson(['audit-recovery-code']);
    }

    public function test_google_only_user_without_mfa_must_sign_in_again_to_enroll(): void
    {
        config()->set('services.google.login_only', true);
        [$user] = $this->userWithStore();
        $this->actingAs($user)->get(route('password.confirm'))->assertOk()
            ->assertSeeText('Sign out and sign in with Google again')
            ->assertDontSee('name="password"', false);
        $this->postJson('/user/two-factor-authentication')->assertStatus(423);
    }

    public function test_confirmation_attempts_are_rate_limited(): void
    {
        config()->set('services.google.login_only', false);
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('password.confirm.store'), ['password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson(route('password.confirm.store'), ['password' => 'wrong'])->assertTooManyRequests();
    }

    private function enableMfa(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['audit-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }
}
