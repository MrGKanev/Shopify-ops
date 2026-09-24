<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install {{ config('app.name') }} · Internal Tools</title>
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-ink">
    <main class="mx-auto flex min-h-screen max-w-4xl items-center px-4 py-10 sm:px-6">
        <div class="w-full rounded-2xl border border-edge bg-surface p-6 shadow-xl shadow-black/5 sm:p-10">
            <div class="mb-8 space-y-2">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-accent">Shopify Ops</p>
                <h1 class="text-3xl font-bold tracking-tight">Set up your application</h1>
                <p class="max-w-2xl text-sm text-muted">Enter an empty database and create the first administrator. The installer runs migrations and permanently locks itself after completion.</p>
            </div>

            @error('installation')
                <div class="mb-6 rounded-lg border border-danger-bd bg-danger-bg px-4 py-3 text-sm text-danger-fg">{{ $message }}</div>
            @enderror

            <form method="POST" action="{{ route('install.store') }}" class="space-y-10">
                @csrf

                <section class="space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold">Database</h2>
                        <p class="mt-1 text-sm text-muted">Create the database first in your hosting panel. SQLite creates its file automatically if its directory is writable.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="field sm:col-span-2">
                            <label for="db_connection">Connection</label>
                            <select id="db_connection" name="db_connection" required>
                                <option value="sqlite" @selected(old('db_connection', 'sqlite') === 'sqlite')>SQLite</option>
                                <option value="mysql" @selected(old('db_connection') === 'mysql')>MySQL</option>
                                <option value="mariadb" @selected(old('db_connection') === 'mariadb')>MariaDB</option>
                            </select>
                            @error('db_connection')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field sm:col-span-2">
                            <label for="database">Database name or SQLite path</label>
                            <input id="database" name="database" value="{{ old('database', $sqliteDatabasePath) }}" required>
                            @error('database')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="db_host">Host <span class="normal-case">(MySQL/MariaDB)</span></label>
                            <input id="db_host" name="db_host" value="{{ old('db_host', '127.0.0.1') }}" autocomplete="off">
                            @error('db_host')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="db_port">Port <span class="normal-case">(MySQL/MariaDB)</span></label>
                            <input id="db_port" name="db_port" type="number" min="1" max="65535" value="{{ old('db_port', '3306') }}">
                            @error('db_port')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="db_username">Username <span class="normal-case">(MySQL/MariaDB)</span></label>
                            <input id="db_username" name="db_username" value="{{ old('db_username') }}" autocomplete="username">
                            @error('db_username')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="db_password">Password <span class="normal-case">(MySQL/MariaDB)</span></label>
                            <input id="db_password" name="db_password" type="password" autocomplete="new-password">
                            @error('db_password')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <section class="space-y-4 border-t border-edge pt-8">
                    <div>
                        <h2 class="text-lg font-semibold">Administrator</h2>
                        <p class="mt-1 text-sm text-muted">This account has complete administrative access.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="field">
                            <label for="name">Name</label>
                            <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
                            @error('name')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="email">Email</label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
                            @error('email')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
                            @error('password')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="password_confirmation">Confirm password</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
                        </div>
                    </div>
                </section>

                <section class="space-y-4 border-t border-edge pt-8">
                    <div>
                        <h2 class="text-lg font-semibold">First Shopify store</h2>
                        <p class="mt-1 text-sm text-muted">You can add more stores later from Administration.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="field">
                            <label for="label">Store name</label>
                            <input id="label" name="label" value="{{ old('label') }}" required>
                            @error('label')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="slug">Store slug</label>
                            <input id="slug" name="slug" value="{{ old('slug') }}" required>
                            @error('slug')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="shopify_store">Shopify store</label>
                            <input id="shopify_store" name="shopify_store" value="{{ old('shopify_store') }}" placeholder="example.myshopify.com" required>
                            @error('shopify_store')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="shopify_access_token">Shopify access token</label>
                            <input id="shopify_access_token" name="shopify_access_token" type="password" required autocomplete="off">
                            @error('shopify_access_token')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="shipstation_api_key">ShipStation API key <span class="normal-case">(optional)</span></label>
                            <input id="shipstation_api_key" name="shipstation_api_key" type="password" autocomplete="off">
                            @error('shipstation_api_key')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="shipstation_api_secret">ShipStation API secret <span class="normal-case">(optional)</span></label>
                            <input id="shipstation_api_secret" name="shipstation_api_secret" type="password" autocomplete="off">
                            @error('shipstation_api_secret')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field sm:col-span-2">
                            <label for="store_number">ShipStation store number <span class="normal-case">(optional)</span></label>
                            <input id="store_number" name="store_number" value="{{ old('store_number') }}">
                            @error('store_number')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <section class="space-y-4 border-t border-edge pt-8">
                    <div>
                        <h2 class="text-lg font-semibold">Notifications <span class="normal-case font-normal text-muted">(optional)</span></h2>
                        <p class="mt-1 text-sm text-muted">Configure your own SMTP and chat webhooks now, or leave these blank and set them later in <code>.env</code>. Without SMTP, mail is only written to the log.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="field sm:col-span-2">
                            <label for="mail_mailer">Mail transport</label>
                            <select id="mail_mailer" name="mail_mailer" required>
                                <option value="log" @selected(old('mail_mailer', 'log') === 'log')>Log only (no real email)</option>
                                <option value="smtp" @selected(old('mail_mailer') === 'smtp')>SMTP</option>
                            </select>
                            @error('mail_mailer')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="mail_host">SMTP host</label>
                            <input id="mail_host" name="mail_host" value="{{ old('mail_host') }}" autocomplete="off">
                            @error('mail_host')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="mail_port">SMTP port</label>
                            <input id="mail_port" name="mail_port" type="number" min="1" max="65535" value="{{ old('mail_port', '587') }}">
                            @error('mail_port')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="mail_username">SMTP username</label>
                            <input id="mail_username" name="mail_username" value="{{ old('mail_username') }}" autocomplete="off">
                            @error('mail_username')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="mail_password">SMTP password</label>
                            <input id="mail_password" name="mail_password" type="password" autocomplete="off">
                            @error('mail_password')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="mail_encryption">SMTP encryption</label>
                            <select id="mail_encryption" name="mail_encryption">
                                <option value="" @selected(old('mail_encryption') === '')>Auto (STARTTLS)</option>
                                <option value="tls" @selected(old('mail_encryption') === 'tls')>Implicit TLS (SSL)</option>
                            </select>
                            @error('mail_encryption')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="mail_from_address">From address</label>
                            <input id="mail_from_address" name="mail_from_address" type="email" value="{{ old('mail_from_address') }}" autocomplete="off">
                            @error('mail_from_address')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="mail_from_name">From name</label>
                            <input id="mail_from_name" name="mail_from_name" value="{{ old('mail_from_name') }}" autocomplete="off">
                            @error('mail_from_name')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="slack_webhook_url">Slack webhook URL <span class="normal-case">(optional)</span></label>
                            <input id="slack_webhook_url" name="slack_webhook_url" type="url" value="{{ old('slack_webhook_url') }}" placeholder="https://hooks.slack.com/services/...">
                            @error('slack_webhook_url')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="discord_webhook_url">Discord webhook URL <span class="normal-case">(optional)</span></label>
                            <input id="discord_webhook_url" name="discord_webhook_url" type="url" value="{{ old('discord_webhook_url') }}" placeholder="https://discord.com/api/webhooks/...">
                            @error('discord_webhook_url')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <div class="flex flex-col-reverse gap-3 border-t border-edge pt-6 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-muted">Do not expose this page after setup; it is automatically disabled once the first account or store exists.</p>
                    <button class="btn" type="submit">Install application</button>
                </div>
            </form>
        </div>
    </main>
</body>
</html>
