<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\Store;
use App\Models\User;
use App\UserRole;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class InitialApplicationInstaller
{
    public function __construct(
        private readonly Application $app,
        private readonly Filesystem $files,
    ) {}

    public function isInstalled(): bool
    {
        try {
            if (! Schema::hasTable('users') || ! Schema::hasTable('stores')) {
                return false;
            }

            return User::query()->exists() || Store::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array{name:string,email:string,password:string,label:string,slug:string,shopify_store:string,shopify_access_token:string,shipstation_api_key:?string,shipstation_api_secret:?string,store_number:?string,db_connection:string,database:string,db_host:?string,db_port:?string,db_username:?string,db_password:?string,mail_mailer:string,mail_host:?string,mail_port:?string,mail_username:?string,mail_password:?string,mail_encryption:string,mail_from_address:?string,mail_from_name:?string,slack_webhook_url:?string,discord_webhook_url:?string}  $data
     */
    public function install(array $data): void
    {
        $this->configureDatabase($data);
        $this->prepareSqliteDatabase($data);
        DB::connection()->getPdo();

        $applicationKey = (string) config('app.key');
        if ($applicationKey === '') {
            $applicationKey = 'base64:'.base64_encode(random_bytes(32));
            config()->set('app.key', $applicationKey);
        }

        $this->writeEnvironment($data, $applicationKey);
        Artisan::call('config:clear');

        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Database migrations could not be completed.');
        }

        DB::transaction(function () use ($data): void {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => UserRole::Admin,
            ]);

            $store = Store::query()->create([
                'label' => $data['label'],
                'slug' => $data['slug'],
                'shopify_store' => $data['shopify_store'],
                'shopify_access_token' => $data['shopify_access_token'],
                'shipstation_api_key' => $data['shipstation_api_key'],
                'shipstation_api_secret' => $data['shipstation_api_secret'],
                'store_number' => $data['store_number'],
            ]);

            $user->stores()->attach($store);
            AppSetting::query()->firstOrCreate(['id' => 1], ['site_name' => config('app.name')]);
        });
    }

    /**
     * @param  array{db_connection:string,database:string,db_host:?string,db_port:?string,db_username:?string,db_password:?string}  $data
     */
    private function configureDatabase(array $data): void
    {
        $connection = $data['db_connection'];
        $configuration = config("database.connections.{$connection}");

        if (! is_array($configuration)) {
            throw new RuntimeException('The selected database connection is not available.');
        }

        $currentConfiguration = $configuration;
        $currentDefaultConnection = config('database.default');
        $configuration['database'] = $data['database'];
        if ($connection !== 'sqlite') {
            $configuration['host'] = $data['db_host'];
            $configuration['port'] = $data['db_port'];
            $configuration['username'] = $data['db_username'];
            $configuration['password'] = $data['db_password'];
            $configuration['url'] = null;
        }

        config()->set('database.default', $connection);
        config()->set("database.connections.{$connection}", $configuration);

        if ($currentDefaultConnection !== $connection || $currentConfiguration !== $configuration) {
            DB::purge($connection);
        }
    }

    /**
     * @param  array{db_connection:string,database:string}  $data
     */
    private function prepareSqliteDatabase(array $data): void
    {
        if ($data['db_connection'] !== 'sqlite' || $data['database'] === ':memory:' || $this->files->exists($data['database'])) {
            return;
        }

        $directory = dirname($data['database']);
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('The SQLite database directory is not writable.');
        }

        $this->files->put($data['database'], '');
    }

    /**
     * @param  array{db_connection:string,database:string,db_host:?string,db_port:?string,db_username:?string,db_password:?string,mail_mailer:string,mail_host:?string,mail_port:?string,mail_username:?string,mail_password:?string,mail_encryption:string,mail_from_address:?string,mail_from_name:?string,slack_webhook_url:?string,discord_webhook_url:?string}  $data
     */
    private function writeEnvironment(array $data, string $applicationKey): void
    {
        $environmentFile = $this->app->environmentFilePath();
        $environmentDirectory = dirname($environmentFile);
        if ((! $this->files->exists($environmentFile) && ! is_writable($environmentDirectory)) || ($this->files->exists($environmentFile) && ! is_writable($environmentFile))) {
            throw new RuntimeException('The environment file is not writable.');
        }

        $contents = $this->files->exists($environmentFile)
            ? $this->files->get($environmentFile)
            : $this->files->get(base_path('.env.example'));

        $variables = [
            'APP_KEY' => $applicationKey,
            'DB_CONNECTION' => $data['db_connection'],
            'DB_URL' => '',
            'DB_DATABASE' => $data['database'],
            'SESSION_DRIVER' => 'database',
        ];

        if ($data['db_connection'] !== 'sqlite') {
            $variables = array_merge($variables, [
                'DB_HOST' => (string) $data['db_host'],
                'DB_PORT' => (string) $data['db_port'],
                'DB_USERNAME' => (string) $data['db_username'],
                'DB_PASSWORD' => (string) $data['db_password'],
            ]);
        }

        $variables['MAIL_MAILER'] = $data['mail_mailer'];
        if ($data['mail_mailer'] === 'smtp') {
            $variables = array_merge($variables, [
                'MAIL_HOST' => (string) $data['mail_host'],
                'MAIL_PORT' => (string) $data['mail_port'],
                'MAIL_USERNAME' => (string) ($data['mail_username'] ?? ''),
                'MAIL_PASSWORD' => (string) ($data['mail_password'] ?? ''),
                'MAIL_FROM_ADDRESS' => (string) $data['mail_from_address'],
                'MAIL_FROM_NAME' => (string) ($data['mail_from_name'] ?? $data['label']),
            ]);
            if ($data['mail_encryption'] === 'tls') {
                $variables['MAIL_SCHEME'] = 'smtps';
            }
        }

        if ($data['slack_webhook_url'] !== null) {
            $variables['SLACK_NOTIFICATION_WEBHOOK_URL'] = $data['slack_webhook_url'];
        }
        if ($data['discord_webhook_url'] !== null) {
            $variables['DISCORD_NOTIFICATION_WEBHOOK_URL'] = $data['discord_webhook_url'];
        }

        foreach ($variables as $key => $value) {
            $line = $key.'='.$this->environmentValue($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $contents = preg_replace($pattern, $line, $contents, -1, $replacements) ?? $contents;

            if ($replacements === 0) {
                $contents .= "\n{$line}\n";
            }
        }

        $this->files->put($environmentFile, $contents);
    }

    private function environmentValue(string $value): string
    {
        if ($value !== '' && preg_match('/^[A-Za-z0-9_+\-.:\/]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
