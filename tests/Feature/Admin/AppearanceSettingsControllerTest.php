<?php

namespace Tests\Feature\Admin;

use App\Models\AppSetting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppearanceSettingsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_update_the_site_name_and_role_targeted_links(): void
    {
        [$admin] = $this->makeUserAndStore('admin');

        $response = $this->actingAs($admin)->from(route('admin.settings'))->put(route('admin.appearance.update'), [
            'site_name' => 'Operations HQ',
            'custom_links' => [
                ['label' => 'Handbook', 'url' => 'https://example.com/handbook', 'audience' => 'all'],
                ['label' => 'Runbook', 'url' => 'https://example.com/runbook', 'audience' => 'operator'],
                ['label' => 'Admin docs', 'url' => 'https://example.com/admin', 'audience' => 'admin'],
                ['label' => '', 'url' => '', 'audience' => 'all'],
            ],
        ]);

        $response->assertRedirect(route('admin.settings'))->assertSessionHas('status', 'Appearance settings updated.');
        $this->assertDatabaseHas('app_settings', ['site_name' => 'Operations HQ']);
        $this->assertSame(3, count(AppSetting::current()->custom_links));

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertSee('<title>Operations HQ · Internal Tools</title>', false)
            ->assertSeeText('Handbook')
            ->assertSeeText('Runbook')
            ->assertSeeText('Admin docs')
            ->assertSeeText('v2.2.0 · GitHub');
    }

    public function test_custom_links_respect_the_configured_audience_and_escape_labels(): void
    {
        AppSetting::factory()->create(['custom_links' => [
            ['label' => '<script>Shared</script>', 'url' => 'https://example.com/shared', 'audience' => 'all'],
            ['label' => 'Operations', 'url' => 'https://example.com/operations', 'audience' => 'operator'],
            ['label' => 'Administration', 'url' => 'https://example.com/administration', 'audience' => 'admin'],
        ]]);
        [$viewer] = $this->makeUserAndStore('viewer');
        [$operator] = $this->makeUserAndStore('operator');

        $this->actingAs($viewer)->get(route('dashboard'))
            ->assertSee('<script>Shared</script>')
            ->assertDontSee('<script>Shared</script>', false)
            ->assertDontSeeText('Operations')
            ->assertDontSeeText('Administration');
        $this->actingAs($operator)->get(route('dashboard'))
            ->assertSeeText('Operations')
            ->assertDontSeeText('Administration');
    }

    public function test_admin_can_replace_and_remove_brand_images(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/old-logo.png', 'old logo');
        Storage::disk('public')->put('branding/old-login.jpg', 'old login');
        $settings = AppSetting::factory()->create([
            'logo_path' => 'branding/old-logo.png',
            'login_image_path' => 'branding/old-login.jpg',
        ]);
        [$admin] = $this->makeUserAndStore('admin');

        $this->actingAs($admin)->put(route('admin.appearance.update'), [
            'site_name' => 'Operations HQ',
            'custom_links' => [],
            'logo' => UploadedFile::fake()->image('logo.png', 600, 200),
            'login_image' => UploadedFile::fake()->image('login.jpg', 1600, 1000),
        ])->assertSessionHasNoErrors();

        $settings->refresh();
        Storage::disk('public')->assertExists($settings->logo_path);
        Storage::disk('public')->assertExists($settings->login_image_path);
        Storage::disk('public')->assertMissing('branding/old-logo.png');
        Storage::disk('public')->assertMissing('branding/old-login.jpg');

        $this->actingAs($admin)->put(route('admin.appearance.update'), [
            'site_name' => 'Operations HQ',
            'custom_links' => [],
            'remove_logo' => '1',
            'remove_login_image' => '1',
        ])->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($settings->logo_path);
        Storage::disk('public')->assertMissing($settings->login_image_path);
        $this->assertNull($settings->fresh()->logo_path);
        $this->assertNull($settings->fresh()->login_image_path);
    }

    public function test_appearance_settings_reject_unsafe_files_links_and_non_admins(): void
    {
        [$admin] = $this->makeUserAndStore('admin');
        [$operator] = $this->makeUserAndStore('operator');
        $payload = [
            'site_name' => 'Operations HQ',
            'custom_links' => [['label' => 'Unsafe', 'url' => 'javascript:alert(1)', 'audience' => 'all']],
            'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
        ];

        $this->actingAs($admin)->from(route('admin.settings'))->put(route('admin.appearance.update'), $payload)
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHasErrors(['custom_links.0.url', 'logo'])
            ->assertSessionHasErrors(['custom_links.0.url' => 'Адресът на допълнителния линк трябва да започва с http:// или https://.']);
        $this->actingAs($operator)->put(route('admin.appearance.update'), [
            'site_name' => 'Forbidden change',
            'custom_links' => [],
        ])->assertForbidden();
        $this->assertDatabaseMissing('app_settings', ['site_name' => 'Forbidden change']);
    }

    /** @return array{User, Store} */
    private function makeUserAndStore(string $role): array
    {
        $user = match ($role) {
            'admin' => User::factory()->admin()->create(),
            'operator' => User::factory()->operator()->create(),
            default => User::factory()->create(),
        };
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
