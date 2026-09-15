<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_can_request_a_password_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'operator@example.com']);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertViewIs('auth.forgot-password')
            ->assertSeeText('Reset your password');
        $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', 'If an account exists for that email, a password reset link has been sent.');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unknown_email_receives_the_same_response_without_a_notification(): void
    {
        Notification::fake();

        $this->from(route('password.request'))->post(route('password.email'), ['email' => 'unknown@example.com'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', 'If an account exists for that email, a password reset link has been sent.');

        Notification::assertNothingSent();
    }

    public function test_valid_token_resets_the_password(): void
    {
        $user = User::factory()->create(['email' => 'operator@example.com', 'password' => 'old-password']);
        $token = Password::createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSeeText('Choose a new password');
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('new-secure-password', (string) $user->fresh()->password));
    }

    public function test_invalid_token_does_not_change_the_password(): void
    {
        $user = User::factory()->create(['email' => 'operator@example.com', 'password' => 'old-password']);

        $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password', (string) $user->fresh()->password));
    }

    public function test_password_reset_requests_are_rate_limited_by_email_and_ip(): void
    {
        Notification::fake();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('password.email'), ['email' => 'operator@example.com'])->assertRedirect();
        }

        $this->post(route('password.email'), ['email' => 'operator@example.com'])->assertTooManyRequests();
        Notification::assertNothingSent();
    }

    public function test_google_only_mode_disables_password_reset(): void
    {
        config()->set('services.google.login_only', true);

        $this->get(route('password.request'))->assertNotFound();
        $this->post(route('password.email'), ['email' => 'operator@example.com'])->assertNotFound();
        $this->get(route('password.reset', ['token' => 'token']))->assertNotFound();
        $this->post(route('password.update'))->assertNotFound();
    }

    public function test_expired_password_reset_tokens_are_scheduled_for_cleanup(): void
    {
        $commands = collect(app(Schedule::class)->events())->pluck('command')->filter()->implode("\n");

        $this->assertStringContainsString('auth:clear-resets', $commands);
    }
}
