# Installation

## Requirements

- PHP 8.5+ with `curl`, `mbstring`, `pdo_sqlite`, and the standard Laravel extensions
- Composer
- Node.js 24+
- pnpm 12.5.1
- SQLite for the default setup
- Redis for Horizon and the default production queue/cache configuration
- `zip` support for backup archives

## Technology

- PHP 8.5 and Laravel 13
- Blade, Tailwind CSS 4, and Vite 8
- SQLite by default; Laravel-supported production databases can also be used
- Laravel queues with Redis/Horizon in production
- PHPUnit, Laravel Pint, and Larastan
- Spatie Activity Log, Backup, CSP, and Health
- Optional Google OAuth, Sentry, Slack, Discord, SMTP, Pulse, and Prometheus metrics

## Local installation

Clone the repository and install the application:

```bash
git clone https://github.com/MrGKanev/Shopify-ops.git
cd Shopify-ops
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan storage:link
pnpm install --frozen-lockfile
pnpm build
php artisan ops:install
```

`ops:install` is interactive. It creates the first administrator, the first store, and the relationship between them. The Shopify token and optional ShipStation credentials entered here are encrypted in the database.

Start the complete development stack:

```bash
composer run dev
```

Open [http://localhost:8000](http://localhost:8000). The development command starts the Laravel server, Vite, log tailing, and Horizon. Redis must be available for Horizon and queued audits.

For UI-only local work without Redis, run the web and frontend processes separately:

```bash
php artisan serve
pnpm dev
```

In local mode the login page also provides development-role login buttons. They are unavailable outside `APP_ENV=local`.

## Hosted/no-shell installation

For a deployment without shell access (shared hosting, a fresh server with only the codebase deployed), visit `/install` instead of running `ops:install`. It walks through database credentials, the first administrator/store, and an optional Notifications step (SMTP, Slack webhook, Discord webhook) that writes directly to `.env` — each deployment configures its own without editing files by hand. The page locks itself permanently once the first user or store exists.

## Next steps

- [Administration](administration.md) — add stores, users, and roles
- [Configuration](configuration.md) — environment variables, notifications, security
- [Operations](operations.md) — queues, scheduled tasks, health, backups
- [Deployment runbook](laravel-deployment-runbook.md) — production setup
