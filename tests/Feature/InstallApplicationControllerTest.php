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

    public function test_installation_requires_smtp_details_when_smtp_is_selected(): void
    {
        $payload = array_merge($this->installationPayload(), ['mail_mailer' => 'smtp']);

        $response = $this
            ->from(route('install.create'))
            ->post(route('install.store'), $payload);

        $response
            ->assertRedirect(route('install.create'))
            ->assertSessionHasErrors([
                'mail_host' => 'The mail host field is required.',
                'mail_port' => 'The mail port field is required.',
                'mail_from_address' => 'The mail from address field is required.',
            ]);
        $this->assertSame(0, User::query()->count());
    }

    public function test_installation_rejects_an_invalid_webhook_url(): void
    {
        $payload = array_merge($this->installationPayload(), ['slack_webhook_url' => 'not-a-url']);

        $response = $this
            ->from(route('install.create'))
            ->post(route('install.store'), $payload);

        $response
            ->assertRedirect(route('install.create'))
            ->assertSessionHasErrors(['slack_webhook_url' => 'The slack webhook url field must be a valid URL.']);
        $this->assertSame(0, User::query()->count());
    }

    public function test_installer_is_not_available_after_an_account_exists(): void
    {
        User::factory()->create();

        $this->get(route('install.create'))->assertNotFound();
    }

    public function test_installation_writes_provided_smtp_and_webhook_settings_to_the_environment(): void
    {
        $payload = array_merge($this->installationPayload(), [
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_port' => '587',
            'mail_username' => 'smtp-user',
            'mail_password' => 'smtp-secret',
            'mail_encryption' => 'tls',
            'mail_from_address' => 'ops@example.com',
            'mail_from_name' => 'Example Ops',
            'slack_webhook_url' => 'https://hooks.slack.test/services/x',
            'discord_webhook_url' => 'https://discord.test/api/webhooks/x',
        ]);

        $this->post(route('install.store'), $payload)->assertRedirect(route('login'));

        $environment = app(Filesystem::class)->get(app()->environmentFilePath());
        $this->assertStringContainsString('MAIL_MAILER=smtp', $environment);
        $this->assertStringContainsString('MAIL_HOST=smtp.example.com', $environment);
        $this->assertStringContainsString('MAIL_PORT=587', $environment);
        $this->assertStringContainsString('MAIL_USERNAME=smtp-user', $environment);
        $this->assertStringContainsString('MAIL_PASSWORD=smtp-secret', $environment);
        $this->assertStringContainsString('MAIL_SCHEME=smtps', $environment);
        $this->assertStringContainsString('MAIL_FROM_ADDRESS="ops@example.com"', $environment);
        $this->assertStringContainsString('SLACK_NOTIFICATION_WEBHOOK_URL=https://hooks.slack.test/services/x', $environment);
        $this->assertStringContainsString('DISCORD_NOTIFICATION_WEBHOOK_URL=https://discord.test/api/webhooks/x', $environment);
    }

    public function test_installation_defaults_to_the_log_mailer_without_notification_settings(): void
    {
        $this->post(route('install.store'), $this->installationPayload())->assertRedirect(route('login'));

        $environment = app(Filesystem::class)->get(app()->environmentFilePath());
        $this->assertStringContainsString('MAIL_MAILER=log', $environment);
        $this->assertStringContainsString("SLACK_NOTIFICATION_WEBHOOK_URL=\n", $environment);
        $this->assertStringContainsString("DISCORD_NOTIFICATION_WEBHOOK_URL=\n", $environment);
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
