<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InstallApplicationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $environmentPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->environmentPath = storage_path('framework/testing/installer-'.bin2hex(random_bytes(8)));
        app(Filesystem::class)->ensureDirectoryExists($this->environmentPath);
        app()->useEnvironmentPath($this->environmentPath);
    }

    protected function tearDown(): void
    {
        app(Filesystem::class)->deleteDirectory($this->environmentPath);

        parent::tearDown();
    }

    public function test_guest_can_render_the_installation_form_when_the_application_is_uninstalled(): void
    {
        $response = $this->get(route('install.create'));

        $response
            ->assertOk()
            ->assertViewIs('install.create')
            ->assertSeeText('Set up your application');
    }

    public function test_installation_creates_the_administrator_and_first_store_then_locks_the_installer(): void
    {
        $response = $this->post(route('install.store'), $this->installationPayload());

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Installation complete. You can now sign in.');

        $this->assertDatabaseHas('users', [
            'name' => 'Jane Admin',
            'email' => 'jane@example.com',
            'role' => 'admin',
        ]);
        $this->assertDatabaseHas('stores', [
            'label' => 'Example Store',
            'slug' => 'example-store',
            'shopify_store' => 'example',
        ]);
        $this->assertDatabaseHas('store_user', [
            'user_id' => User::query()->sole()->id,
            'store_id' => Store::query()->sole()->id,
        ]);
        $this->assertStringContainsString('DB_CONNECTION=sqlite', app(Filesystem::class)->get(app()->environmentFilePath()));

        $this->get(route('install.create'))->assertNotFound();
    }

    public function test_installation_requires_the_setup_details(): void
    {
        $response = $this
            ->from(route('install.create'))
            ->post(route('install.store'));

        $response
            ->assertRedirect(route('install.create'))
            ->assertSessionHasErrors([
                'db_connection' => 'The db connection field is required.',
                'database' => 'The database field is required.',
                'name' => 'The name field is required.',
                'email' => 'The email field is required.',
                'password' => 'The password field is required.',
                'label' => 'The label field is required.',
                'slug' => 'The slug field is required.',
                'shopify_store' => 'The shopify store field is required.',
                'shopify_access_token' => 'The shopify access token field is required.',
            ]);
    }

    public function test_installer_is_not_available_after_an_account_exists(): void
    {
        User::factory()->create();

        $this->get(route('install.create'))->assertNotFound();
    }

    /** @return array<string, string> */
    private function installationPayload(): array
    {
        return [
            'db_connection' => 'sqlite',
            'database' => (string) config('database.connections.sqlite.database'),
            'name' => 'Jane Admin',
            'email' => 'jane@example.com',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
            'label' => 'Example Store',
            'slug' => 'example-store',
            'shopify_store' => 'example.myshopify.com',
            'shopify_access_token' => 'shopify-access-token',
            'shipstation_api_key' => '',
            'shipstation_api_secret' => '',
            'store_number' => '',
        ];
    }
}
