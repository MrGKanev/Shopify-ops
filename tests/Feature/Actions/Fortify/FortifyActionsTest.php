<?php

namespace Tests\Feature\Actions\Fortify;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FortifyActionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string STRONG_PASSWORD = 'Str0ng-Passphrase!42';

    public function test_a_new_user_is_created_with_a_hashed_password(): void
    {
        $user = (new CreateNewUser)->create(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD]);

        $this->assertSame(['Jane Doe', 'jane@example.com'], [$user->name, $user->email]);
        $this->assertNotSame(self::STRONG_PASSWORD, $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $user->getRawOriginal('password')));
    }

    public function test_registration_rejects_duplicate_email_weak_or_unconfirmed_passwords_and_oversized_names(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $valid = ['name' => 'Jane', 'email' => 'jane@example.com', 'password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD];

        foreach ([
            'email' => ['email' => 'taken@example.com'],
            'password' => ['password' => 'short', 'password_confirmation' => 'short'],
            'name' => ['name' => str_repeat('a', 256)],
        ] as $field => $override) {
            try {
                (new CreateNewUser)->create($override + $valid);
                $this->fail("Registration must reject an invalid {$field}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
        try {
            (new CreateNewUser)->create(['password_confirmation' => 'different'] + $valid);
            $this->fail('Registration must require a matching confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_forgotten_password_is_replaced_only_with_a_valid_confirmed_one(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);

        try {
            (new ResetUserPassword)->reset($user, ['password' => 'short', 'password_confirmation' => 'short']);
            $this->fail('A weak password must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(Hash::check('old-password-123', $user->fresh()->getRawOriginal('password')));
        }

        (new ResetUserPassword)->reset($user, ['password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD]);

        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $user->fresh()->getRawOriginal('password')));
    }

    public function test_changing_the_password_requires_the_current_one_and_reports_errors_in_its_own_bag(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $this->actingAs($user);

        try {
            (new UpdateUserPassword)->update($user, ['current_password' => 'wrong', 'password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD]);
            $this->fail('A wrong current password must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('updatePassword', $exception->errorBag);
            $this->assertSame([__('The provided password does not match your current password.')], $exception->errors()['current_password']);
        }
        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->getRawOriginal('password')));

        (new UpdateUserPassword)->update($user, ['current_password' => 'old-password-123', 'password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD]);

        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $user->fresh()->getRawOriginal('password')));
    }

    public function test_profile_updates_allow_keeping_the_own_email_but_not_taking_anothers(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com']);
        User::factory()->create(['email' => 'other@example.com']);

        (new UpdateUserProfileInformation)->update($user, ['name' => 'New Name', 'email' => 'me@example.com']);
        $this->assertSame('New Name', $user->fresh()->name);

        (new UpdateUserProfileInformation)->update($user, ['name' => 'New Name', 'email' => 'changed@example.com']);
        $this->assertSame('changed@example.com', $user->fresh()->email);

        try {
            (new UpdateUserProfileInformation)->update($user, ['name' => 'New Name', 'email' => 'other@example.com']);
            $this->fail('An email owned by another user must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('updateProfileInformation', $exception->errorBag);
            $this->assertArrayHasKey('email', $exception->errors());
        }
        $this->assertSame('changed@example.com', $user->fresh()->email);
    }
}
